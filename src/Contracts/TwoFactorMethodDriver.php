<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Contracts;

use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Gabrielesbaiz\NovaTwoFactor\Results\ChallengeContext;
use Gabrielesbaiz\NovaTwoFactor\Results\EnrollmentIntent;
use Gabrielesbaiz\NovaTwoFactor\Results\VerificationResult;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * One factor type.
 *
 * Fortify's pipeline assumes a single TOTP secret on the user row; this
 * interface is what replaces that assumption, and it is also the extension
 * point for a host app adding a fifth factor via `TwoFactor::extend()`.
 */
interface TwoFactorMethodDriver
{
    public function type(): MethodType;

    /**
     * Whether this driver can be used at all: enabled in config, and any
     * optional dependency actually present.
     */
    public function isAvailable(): bool;

    /**
     * Begin an enrollment. Must be idempotent for the lifetime of the pending
     * enrollment: reloading the setup page has to return the same secret, or a
     * user who refreshes mid-setup is silently locked out of the QR they just
     * scanned.
     *
     * @param  array<string, mixed>  $input
     */
    public function beginEnrollment(Authenticatable $user, array $input = []): EnrollmentIntent;

    /**
     * Finish an enrollment by proving possession, persisting the confirmed
     * method. Throws when the proof does not hold.
     *
     * @param  array<string, mixed>  $input
     */
    public function completeEnrollment(Authenticatable $user, array $input): TwoFactorMethod;

    /**
     * Prepare a challenge — sending a code, or building ceremony options.
     * Returns whatever the client needs; null when nothing is needed up front,
     * as with TOTP.
     *
     * @return array<string, mixed>|null
     */
    public function beginChallenge(TwoFactorMethod $method, ChallengeContext $context): ?array;

    /**
     * @param  array<string, mixed>  $input
     */
    public function verify(TwoFactorMethod $method, array $input, ChallengeContext $context): VerificationResult;

    /**
     * A label for a newly enrolled method, when the user has not named it.
     *
     * @param  array<string, mixed>  $input
     */
    public function suggestName(array $input = []): string;
}
