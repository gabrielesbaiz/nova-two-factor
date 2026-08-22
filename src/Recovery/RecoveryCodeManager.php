<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Recovery;

use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorRecoveryCode;
use Gabrielesbaiz\NovaTwoFactor\Results\VerificationResult;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generation, storage and single use of recovery codes.
 *
 * 1.x issued exactly one code, upper-cased it (collapsing base62 to base36 and
 * throwing away ~44 bits), bcrypt-hashed it, and — worst of all — deleted the
 * user's entire 2FA record when it was used, turning a backup code into a
 * self-service way to switch 2FA off.
 */
class RecoveryCodeManager
{
    /**
     * Issue a fresh set, invalidating every existing code.
     *
     * Wrapped in a transaction so there is never a window where the user holds
     * codes that no longer work but has not yet been shown the new ones.
     *
     * @return Collection<int, string> The plaintext codes. Returned once, never stored.
     */
    public function regenerate(Authenticatable $user): Collection
    {
        $count = max(1, (int) Config::get('nova-two-factor.recovery_codes.count', 8));

        $codes = Collection::times($count, fn (): string => $this->generateCode());

        DB::transaction(function () use ($user, $codes): void {
            $this->query($user)->delete();

            $now = now();

            $this->newModel()->newQuery()->insert(
                $codes->map(fn (string $code): array => [
                    'authenticatable_type' => $user->getMorphClass(),
                    'authenticatable_id' => $user->getKey(),
                    'code_hash' => $this->hash($code),
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all(),
            );
        });

        return $codes;
    }

    /**
     * Spend a code.
     *
     * The lookup is a single indexed equality on the hash — which is exactly why
     * these are SHA-256 and not bcrypt. With a slow KDF you must load every row
     * for the user and loop `Hash::check()`, which under credential stuffing is
     * a CPU exhaustion vector. At ~119 bits of entropy a KDF buys nothing that
     * the entropy has not already bought.
     */
    public function consume(Authenticatable $user, string $code): VerificationResult
    {
        $normalized = $this->normalize($code);

        if ($normalized === '') {
            return VerificationResult::failed(VerificationResult::INVALID_CODE);
        }

        $hash = $this->hash($normalized);

        /** @var TwoFactorRecoveryCode|null $record */
        $record = $this->query($user)->where('code_hash', $hash)->first();

        if (! $record instanceof TwoFactorRecoveryCode) {
            return VerificationResult::failed(VerificationResult::INVALID_CODE);
        }

        // Belt and braces on top of the indexed lookup: the comparison that
        // actually decides the outcome is constant-time.
        if (! hash_equals($record->code_hash, $hash)) {
            return VerificationResult::failed(VerificationResult::INVALID_CODE);
        }

        if ($record->used_at !== null) {
            return VerificationResult::failed(VerificationResult::ALREADY_USED);
        }

        if (! $record->consume(request()->ip())) {
            // Lost the race with a concurrent use of the same code.
            return VerificationResult::failed(VerificationResult::ALREADY_USED);
        }

        return VerificationResult::passed();
    }

    public function unusedCount(Authenticatable $user): int
    {
        return $this->query($user)->whereNull('used_at')->count();
    }

    public function isRunningLow(Authenticatable $user): bool
    {
        return $this->unusedCount($user) <= (int) Config::get('nova-two-factor.recovery_codes.warn_at', 3);
    }

    public function clear(Authenticatable $user): void
    {
        $this->query($user)->delete();
    }

    /**
     * A code in `xxxxxxxxxx-xxxxxxxxxx` form.
     *
     * `Str::random` is base62 and CSPRNG-backed. Case is preserved on purpose —
     * upper-casing is what cost 1.x most of its entropy.
     */
    protected function generateCode(): string
    {
        $length = max(6, (int) Config::get('nova-two-factor.recovery_codes.length', 10));

        return Str::random($length).'-'.Str::random($length);
    }

    /**
     * Strip formatting but keep case.
     *
     * Users paste these with the dash, without it, and occasionally with a
     * stray space from a PDF, so all three have to resolve to the same hash.
     */
    protected function normalize(string $code): string
    {
        return preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '';
    }

    protected function hash(string $normalized): string
    {
        return hash('sha256', $this->normalize($normalized));
    }

    protected function newModel(): TwoFactorRecoveryCode
    {
        return new TwoFactorRecoveryCode;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<TwoFactorRecoveryCode>
     */
    protected function query(Authenticatable $user): \Illuminate\Database\Eloquent\Builder
    {
        $query = $this->newModel()->newQuery();

        if ($user instanceof Model) {
            return $query->whereMorphedTo('authenticatable', $user);
        }

        return $query
            ->where('authenticatable_type', $user->getMorphClass())
            ->where('authenticatable_id', $user->getAuthIdentifier());
    }
}
