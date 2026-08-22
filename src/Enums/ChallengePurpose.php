<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Enums;

enum ChallengePurpose: string
{
    /** Second factor during login. */
    case Login = 'login';

    /** Re-proof in front of one sensitive action. */
    case StepUp = 'step-up';

    /** Proving possession while adding a new method. */
    case Enrollment = 'enrollment';

    /**
     * Whether user verification must be enforced regardless of configuration.
     * Step-up exists to establish freshness, so a silent assertion is not enough.
     */
    public function requiresUserVerification(): bool
    {
        return $this === self::StepUp;
    }

    /**
     * Whether "remember this device" may be offered. Never during a step-up:
     * the entire point is that the proof is fresh.
     */
    public function allowsTrustedDevice(): bool
    {
        return $this === self::Login;
    }
}
