<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Actions;

use Gabrielesbaiz\NovaTwoFactor\Events\TwoFactorReset;
use Gabrielesbaiz\NovaTwoFactor\Recovery\RecoveryCodeManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Wipes every factor, recovery code and trusted device for a user.
 *
 * The break-glass path for an administrator, and for the CLI. Always attributed
 * and always audited: an unexplained reset of somebody else's second factor is
 * indistinguishable from an attack.
 */
class ResetTwoFactor
{
    public function __construct(private readonly RecoveryCodeManager $recoveryCodes) {}

    public function __invoke(
        Authenticatable $user,
        string $reason,
        ?Authenticatable $performedBy = null,
    ): void {
        DB::transaction(function () use ($user): void {
            // Methods first: challenges cascade from them.
            $user->twoFactorMethods()->delete();
            $user->twoFactorTrustedDevices()->delete();
            $this->recoveryCodes->clear($user);
        });

        event(new TwoFactorReset($user, null, array_filter([
            'reason' => $reason,
            'performed_by' => $performedBy?->getAuthIdentifier(),
            'performed_by_type' => $performedBy?->getMorphClass(),
        ], static fn (mixed $value): bool => $value !== null)));
    }
}
