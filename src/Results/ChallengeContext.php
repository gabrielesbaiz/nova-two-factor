<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Results;

use Gabrielesbaiz\NovaTwoFactor\Enums\ChallengePurpose;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who is being challenged, why, and from where.
 *
 * Carrying the purpose explicitly is what lets a driver tighten its own rules
 * without consulting global config — a step-up forces user verification on a
 * passkey no matter what the configuration says.
 */
final readonly class ChallengeContext
{
    public function __construct(
        public Authenticatable $user,
        public ChallengePurpose $purpose,
        public ?string $ip = null,
        public ?string $userAgent = null,
        public ?string $scope = null,
    ) {}

    public function requiresUserVerification(): bool
    {
        return $this->purpose->requiresUserVerification();
    }

    public function allowsTrustedDevice(): bool
    {
        return $this->purpose->allowsTrustedDevice();
    }
}
