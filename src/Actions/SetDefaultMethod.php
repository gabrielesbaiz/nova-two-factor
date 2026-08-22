<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Actions;

use Gabrielesbaiz\NovaTwoFactor\Events\DefaultMethodChanged;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

class SetDefaultMethod
{
    public function __invoke(Authenticatable $user, TwoFactorMethod $method): void
    {
        abort_unless($method->isConfirmed(), 422);

        DB::transaction(function () use ($user, $method): void {
            // Exactly one default, always. Two would make the challenge's choice
            // arbitrary; none would make it empty.
            $user->twoFactorMethods()->update(['is_default' => false]);

            $method->forceFill(['is_default' => true])->save();
        });

        event(new DefaultMethodChanged($user, $method));
    }
}
