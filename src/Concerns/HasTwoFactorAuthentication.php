<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Concerns;

use Carbon\CarbonInterface;
use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorAudit;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorRecoveryCode;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorTrustedDevice;
use Gabrielesbaiz\NovaTwoFactor\Support\Enforcement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Config;

/**
 * Add to any authenticatable model that should be able to hold second factors.
 *
 * Deliberately small. 1.x shipped six near-identical predicates that were exact
 * negations of one another, and the enforcement middleware picked the wrong one
 * — which is how unenrolled users walked straight past the gate.
 */
trait HasTwoFactorAuthentication
{
    /**
     * @return MorphMany<TwoFactorMethod, $this>
     */
    public function twoFactorMethods(): MorphMany
    {
        return $this->morphMany(TwoFactorMethod::class, 'authenticatable');
    }

    /**
     * @return MorphMany<TwoFactorRecoveryCode, $this>
     */
    public function twoFactorRecoveryCodes(): MorphMany
    {
        return $this->morphMany(TwoFactorRecoveryCode::class, 'authenticatable');
    }

    /**
     * @return MorphMany<TwoFactorTrustedDevice, $this>
     */
    public function twoFactorTrustedDevices(): MorphMany
    {
        return $this->morphMany(TwoFactorTrustedDevice::class, 'authenticatable');
    }

    /**
     * @return MorphMany<TwoFactorAudit, $this>
     */
    public function twoFactorAudits(): MorphMany
    {
        return $this->morphMany(TwoFactorAudit::class, 'authenticatable');
    }

    /**
     * Methods that can actually satisfy a challenge. Unconfirmed enrollments are
     * excluded: a half-finished setup must never gate a login.
     *
     * @return Collection<int, TwoFactorMethod>
     */
    public function confirmedTwoFactorMethods(): Collection
    {
        return $this->twoFactorMethods()
            ->confirmed()
            ->orderByDesc('is_default')
            ->orderByDesc('last_used_at')
            ->get();
    }

    /**
     * The single predicate the challenge and the enforcement gate both consult.
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->twoFactorMethods()->confirmed()->exists();
    }

    public function hasTwoFactorMethod(MethodType|string $type): bool
    {
        return $this->twoFactorMethods()->confirmed()->ofType($type)->exists();
    }

    /**
     * The method offered first at the challenge: the user's explicit default,
     * otherwise the strongest one they hold.
     */
    public function defaultTwoFactorMethod(): ?TwoFactorMethod
    {
        $methods = $this->confirmedTwoFactorMethods();

        if ($methods->isEmpty()) {
            return null;
        }

        $explicit = $methods->firstWhere('is_default', true);

        if ($explicit instanceof TwoFactorMethod) {
            return $explicit;
        }

        foreach (MethodType::byStrength() as $type) {
            $match = $methods->firstWhere('type', $type);

            if ($match instanceof TwoFactorMethod) {
                return $match;
            }
        }

        return $methods->first();
    }

    public function unusedTwoFactorRecoveryCodeCount(): int
    {
        return $this->twoFactorRecoveryCodes()->unused()->count();
    }

    public function isRunningLowOnTwoFactorRecoveryCodes(): bool
    {
        $threshold = (int) Config::get('nova-two-factor.recovery_codes.warn_at', 3);

        return $this->hasTwoFactorEnabled()
            && $this->unusedTwoFactorRecoveryCodeCount() <= $threshold;
    }

    public function requiresTwoFactorAuthentication(): bool
    {
        return app(Enforcement::class)->appliesTo($this);
    }

    public function twoFactorGraceEndsAt(): ?CarbonInterface
    {
        return app(Enforcement::class)->graceEndsAt($this);
    }

    /**
     * A stable 32-byte WebAuthn user handle.
     *
     * Derived rather than stored: it needs no column, no migration and no
     * backfill, and discoverable-credential lookups resolve by credential id
     * anyway. Keyed on the app key so it is not simply the primary key in
     * disguise, and includes the morph class so two models cannot collide.
     */
    public function twoFactorUserHandle(): string
    {
        return hash_hmac(
            'sha256',
            $this->getMorphClass().'|'.$this->getKey(),
            (string) Config::get('app.key'),
            binary: true,
        );
    }

    /**
     * Users with no confirmed method. Written as a `whereDoesntHave` so the
     * compliance metric stays one query on a ten-thousand-row table.
     */
    public function scopeWithoutTwoFactor(Builder $query): Builder
    {
        return $query->whereDoesntHave(
            'twoFactorMethods',
            fn (Builder $methods): Builder => $methods->confirmed(),
        );
    }

    public function scopeWithTwoFactor(Builder $query): Builder
    {
        return $query->whereHas(
            'twoFactorMethods',
            fn (Builder $methods): Builder => $methods->confirmed(),
        );
    }
}
