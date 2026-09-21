<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Otp;

use Gabrielesbaiz\NovaTwoFactor\Enums\ChallengePurpose;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorChallenge;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Gabrielesbaiz\NovaTwoFactor\Results\VerificationResult;
use Gabrielesbaiz\NovaTwoFactor\Support\MorphOwner;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Issues, stores and verifies out-of-band numeric codes.
 */
class OtpCodeManager
{
    /**
     * Mint a code and record it, invalidating any still-live code for the
     * method.
     *
     * That invalidation matters more than it looks: leaving N outstanding codes
     * multiplies the guess surface by N against a value that only carries about
     * twenty bits to begin with.
     *
     * @return array{0: TwoFactorChallenge, 1: string} The record, and the plaintext code to send.
     */
    public function issue(
        Authenticatable $user,
        TwoFactorMethod $method,
        ChallengePurpose $purpose,
    ): array {
        $code = $this->generateCode();
        $ttl = max(30, (int) Config::get('nova-two-factor.methods.email.ttl', 300));

        $challenge = DB::transaction(function () use ($user, $method, $purpose, $code, $ttl): TwoFactorChallenge {
            TwoFactorChallenge::query()
                ->where('method_id', $method->getKey())
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            $challenge = new TwoFactorChallenge([
                'method_id' => $method->getKey(),
                'purpose' => $purpose,
                'code_hash' => $this->hash($code),
                'sent_at' => now(),
                'expires_at' => now()->addSeconds($ttl),
            ]);

            $challenge->authenticatable()->associate(MorphOwner::model($user));
            $challenge->save();

            return $challenge;
        });

        $this->rememberIssued($user, $code);

        return [$challenge, $code];
    }

    /**
     * Verify a submitted code against the live challenge for a method.
     */
    public function verify(TwoFactorMethod $method, string $submitted): VerificationResult
    {
        $submitted = $this->normalize($submitted);

        /** @var TwoFactorChallenge|null $challenge */
        $challenge = TwoFactorChallenge::query()
            ->where('method_id', $method->getKey())
            ->whereNull('consumed_at')
            ->latest('id')
            ->first();

        if (! $challenge instanceof TwoFactorChallenge) {
            return VerificationResult::failed(VerificationResult::NO_METHOD, $method);
        }

        if (! $challenge->isLive()) {
            return VerificationResult::failed(VerificationResult::EXPIRED, $method);
        }

        $maxAttempts = max(1, (int) Config::get('nova-two-factor.methods.email.max_attempts', 5));

        if (! hash_equals($challenge->code_hash, $this->hash($submitted))) {
            // Burn the challenge once the budget is spent. Without this, a user
            // simply requests a new code and keeps their old guess allowance —
            // which turns a five-attempt limit into an unlimited one.
            if ($challenge->registerFailedAttempt($maxAttempts)) {
                $challenge->consume();

                return VerificationResult::failed(VerificationResult::ATTEMPTS_EXHAUSTED, $method);
            }

            return VerificationResult::failed(
                $this->wasSuperseded($method, $submitted, $challenge)
                    ? VerificationResult::SUPERSEDED
                    : VerificationResult::INVALID_CODE,
                $method,
            );
        }

        if (! $challenge->consume()) {
            return VerificationResult::failed(VerificationResult::ALREADY_USED, $method);
        }

        $method->touchLastUsed();

        return VerificationResult::passed($method);
    }

    /**
     * Whether a code may be sent right now.
     *
     * The cooldown throttles *resending* a code the user already has — it must
     * not stand between them and their first one. Without that distinction,
     * enrolling and then signing in within the cooldown leaves the user at a
     * challenge screen that refuses to send them anything.
     *
     * Enforced server-side against `sent_at`; the countdown the UI shows is
     * only a courtesy and must never be the thing that holds the line.
     */
    /**
     * The code already in the user's inbox, if it is still good.
     *
     * Re-entering enrollment used to mint a fresh code and kill the one that
     * had just been sent, so a user who clicked "Email code" twice was reading
     * a dead code in the newest-looking mail.
     */
    public function liveChallenge(TwoFactorMethod $method): ?TwoFactorChallenge
    {
        $latest = $this->latestChallenge($method);

        return $latest instanceof TwoFactorChallenge && $latest->isLive() ? $latest : null;
    }

    public function canResend(TwoFactorMethod $method): bool
    {
        $latest = $this->latestChallenge($method);

        if (! $latest instanceof TwoFactorChallenge) {
            return true;
        }

        // Nothing live to resend: the last code was spent or has expired, so
        // this is a fresh request rather than an impatient one.
        if (! $latest->isLive()) {
            return true;
        }

        return $latest->canResend((int) Config::get('nova-two-factor.methods.email.resend_after', 60));
    }

    public function secondsUntilResend(TwoFactorMethod $method): int
    {
        $latest = $this->latestChallenge($method);

        if (! $latest instanceof TwoFactorChallenge || ! $latest->isLive()) {
            return 0;
        }

        $available = $latest->sent_at->addSeconds(
            (int) Config::get('nova-two-factor.methods.email.resend_after', 60),
        );

        // Carbon 3 returns a float here, and this method promises an int.
        return max(0, (int) ceil(now()->diffInSeconds($available, false)));
    }

    /**
     * A uniformly distributed numeric code.
     *
     * `random_int` rather than `Str::random`, because the value has to be
     * digits and it has to be uniform across the whole range — zero-padding a
     * smaller number would skew it.
     */
    protected function generateCode(): string
    {
        $digits = max(4, min(10, (int) Config::get('nova-two-factor.methods.email.digits', 6)));
        $max = (10 ** $digits) - 1;

        return str_pad((string) random_int(0, $max), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Keyed HMAC, not a bare digest.
     *
     * A six-digit code is only about twenty bits: an unkeyed SHA-256 of a
     * leaked challenges table is exhausted in microseconds, whereas an HMAC
     * keyed on the application key is useless without it.
     */
    protected function hash(string $code): string
    {
        return hash_hmac('sha256', $this->normalize($code), (string) Config::get('app.key'));
    }

    /**
     * Whether the digits typed were a code we ourselves replaced.
     *
     * Only ever consulted after a failed comparison, and only against codes
     * issued to this same method, so it reveals nothing an attacker could not
     * already establish by guessing. What it buys is the difference between
     * "you mistyped" and "you are reading the wrong email", which is the
     * difference between a user who retries and one who gives up.
     */
    protected function wasSuperseded(TwoFactorMethod $method, string $submitted, TwoFactorChallenge $live): bool
    {
        $hash = $this->hash($submitted);

        // The rows first: cheap, and covers the ordinary case.
        $inRows = TwoFactorChallenge::query()
            ->where('authenticatable_type', $method->authenticatable_type)
            ->where('authenticatable_id', $method->authenticatable_id)
            ->whereKeyNot($live->getKey())
            ->whereNotNull('consumed_at')
            ->where('created_at', '>=', now()->subDay())
            ->get()
            ->contains(static fn (TwoFactorChallenge $previous): bool => hash_equals($previous->code_hash, $hash));

        if ($inRows) {
            return true;
        }

        // Then the short-lived record kept outside them. Challenges cascade
        // when a method is deleted, so an administrative reset — or simply
        // starting enrollment over — takes with it every trace of the codes
        // already sitting in the user's inbox. Those are precisely the ones
        // they are most likely to type next, and without this they come back
        // as "that code is not correct", which sends them to retype it.
        return $this->wasRecentlyIssued($method, $submitted);
    }

    /**
     * Note that a code was issued, independently of the row that holds it.
     *
     * Hashes only, the same keyed HMAC already stored in the table, capped and
     * short-lived: enough to recognise a code we ourselves killed, useless to
     * anybody who cannot already read the cache *and* the application key.
     */
    protected function rememberIssued(Authenticatable $user, string $code): void
    {
        $key = $this->issuedKey($user->getMorphClass(), (string) $user->getAuthIdentifier());

        /** @var array<int, string> $hashes */
        $hashes = (array) Cache::get($key, []);

        $hashes[] = $this->hash($code);

        Cache::put($key, array_slice(array_unique($hashes), -20), now()->addDay());
    }

    protected function wasRecentlyIssued(TwoFactorMethod $method, string $submitted): bool
    {
        $key = $this->issuedKey(
            (string) $method->authenticatable_type,
            (string) $method->authenticatable_id,
        );

        $hash = $this->hash($submitted);

        foreach ((array) Cache::get($key, []) as $previous) {
            if (is_string($previous) && hash_equals($previous, $hash)) {
                return true;
            }
        }

        return false;
    }

    protected function issuedKey(string $type, string $id): string
    {
        return 'nova-two-factor:issued-codes|'.$type.'|'.$id;
    }

    protected function normalize(string $code): string
    {
        return preg_replace('/\D/', '', $code) ?? '';
    }

    protected function latestChallenge(TwoFactorMethod $method): ?TwoFactorChallenge
    {
        /** @var TwoFactorChallenge|null $latest */
        $latest = TwoFactorChallenge::query()
            ->where('method_id', $method->getKey())
            ->latest('id')
            ->first();

        return $latest;
    }
}
