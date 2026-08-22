<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Facades;

use Gabrielesbaiz\NovaTwoFactor\TwoFactorManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Gabrielesbaiz\NovaTwoFactor\Contracts\TwoFactorMethodDriver driver(\Gabrielesbaiz\NovaTwoFactor\Enums\MethodType|string $type)
 * @method static void extend(string $type, \Closure $factory)
 * @method static \Illuminate\Support\Collection<int, \Gabrielesbaiz\NovaTwoFactor\Contracts\TwoFactorMethodDriver> availableDrivers()
 * @method static bool hasConfirmedMethods(\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static \Gabrielesbaiz\NovaTwoFactor\Recovery\RecoveryCodeManager recoveryCodes()
 * @method static \Gabrielesbaiz\NovaTwoFactor\Support\Enforcement enforcement()
 * @method static \Gabrielesbaiz\NovaTwoFactor\Results\VerificationResult verify(\Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod $method, array<string, mixed> $input, \Gabrielesbaiz\NovaTwoFactor\Results\ChallengeContext $context)
 * @method static \Gabrielesbaiz\NovaTwoFactor\Results\VerificationResult consumeRecoveryCode(\Illuminate\Contracts\Auth\Authenticatable $user, string $code, \Gabrielesbaiz\NovaTwoFactor\Results\ChallengeContext $context)
 * @method static \Gabrielesbaiz\NovaTwoFactor\Results\ChallengeContext context(\Illuminate\Contracts\Auth\Authenticatable $user, \Gabrielesbaiz\NovaTwoFactor\Enums\ChallengePurpose $purpose, ?string $scope = null)
 *
 * @see TwoFactorManager
 */
class TwoFactor extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return TwoFactorManager::class;
    }
}
