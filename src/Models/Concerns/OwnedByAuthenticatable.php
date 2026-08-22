<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Models\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * @property string $authenticatable_type
 * @property int|string $authenticatable_id
 */
trait OwnedByAuthenticatable
{
    /**
     * Whether this record belongs to the given user.
     *
     * Every route already scopes its query to the authenticated user, so this
     * is defence in depth rather than the primary control — but an unscoped
     * query added later should fail loudly instead of quietly acting on
     * somebody else's credential.
     */
    public function isOwnedBy(Authenticatable $user): bool
    {
        return $this->authenticatable_type === $user->getMorphClass()
            && (string) $this->authenticatable_id === (string) $user->getAuthIdentifier();
    }
}
