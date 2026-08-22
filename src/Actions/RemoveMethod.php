<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Actions;

use Gabrielesbaiz\NovaTwoFactor\Events\MethodRemoved;
use Gabrielesbaiz\NovaTwoFactor\Exceptions\LastFactorException;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Gabrielesbaiz\NovaTwoFactor\Support\Enforcement;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Removes one factor.
 *
 * The caller is responsible for the password confirmation — that is enforced by
 * middleware on the route, so it cannot be forgotten here. What this class owns
 * is the rule that policy cannot be escaped by deleting your way out of it.
 */
class RemoveMethod
{
    public function __construct(private readonly Enforcement $enforcement) {}

    public function __invoke(Authenticatable $user, TwoFactorMethod $method): void
    {
        $this->assertOwnedBy($user, $method);

        if ($this->wouldLeaveUserUnprotected($user, $method)) {
            throw LastFactorException::because('last_factor_required');
        }

        $type = $method->type->value;
        $name = $method->name;
        $wasDefault = $method->is_default;

        DB::transaction(function () use ($user, $method, $wasDefault): void {
            $method->delete();

            // Never leave an account with methods but no default: the challenge
            // would have nothing to offer first.
            if ($wasDefault) {
                $this->promoteNewDefault($user);
            }
        });

        event(new MethodRemoved($user, null, [
            'method_type' => $type,
            'name' => $name,
        ]));
    }

    protected function assertOwnedBy(Authenticatable $user, TwoFactorMethod $method): void
    {
        abort_unless($method->isOwnedBy($user), 403);
    }

    /**
     * Whether this deletion would strand a user that policy requires to hold a
     * factor with none at all.
     *
     * The 1.x hole was worse than this: `toggle2Fa()` switched protection off
     * entirely with no password, no code and no audit trail.
     */
    protected function wouldLeaveUserUnprotected(Authenticatable $user, TwoFactorMethod $method): bool
    {
        if (! $this->enforcement->appliesTo($user) || ! $this->enforcement->mode()->blocks()) {
            return false;
        }

        $remaining = $user->twoFactorMethods()
            ->confirmed()
            ->whereKeyNot($method->getKey())
            ->count();

        return $remaining === 0;
    }

    protected function promoteNewDefault(Authenticatable $user): void
    {
        $next = $user->twoFactorMethods()
            ->confirmed()
            ->orderByDesc('last_used_at')
            ->first();

        $next?->forceFill(['is_default' => true])->save();
    }
}
