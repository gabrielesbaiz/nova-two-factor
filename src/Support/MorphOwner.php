<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Associating a morph relation needs an Eloquent model, while the framework
 * hands us an `Authenticatable`. Every real Nova user model is both, but that
 * has to be asserted once rather than assumed in a dozen places.
 */
final class MorphOwner
{
    public static function model(Authenticatable $user): Model
    {
        if ($user instanceof Model) {
            return $user;
        }

        throw new InvalidArgumentException(sprintf(
            'Two-factor authentication requires an Eloquent user model; [%s] is not one.',
            $user::class,
        ));
    }
}
