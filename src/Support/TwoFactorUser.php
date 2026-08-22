<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Bridges Nova's boundary, where models arrive typed as plain `Model`, to the
 * package's own APIs, which need an `Authenticatable` carrying the trait.
 *
 * Every real Nova user model is all three, but the analyser cannot know that
 * from a resource or a metric — and asserting it once here is better than
 * widening every signature in the package to `Model|Authenticatable`.
 */
final class TwoFactorUser
{
    /**
     * @phpstan-assert Authenticatable $user
     */
    public static function assert(Model|Authenticatable $user): Authenticatable
    {
        if ($user instanceof Authenticatable && method_exists($user, 'twoFactorMethods')) {
            return $user;
        }

        throw new InvalidArgumentException(sprintf(
            '[%s] must be authenticatable and use the HasTwoFactorAuthentication trait.',
            $user::class,
        ));
    }

    /**
     * The non-throwing variant, for read-only display paths where a model
     * without the trait should simply render as "unavailable" rather than break
     * an entire resource index.
     */
    public static function tryFrom(mixed $user): ?Authenticatable
    {
        return $user instanceof Authenticatable && method_exists($user, 'twoFactorMethods')
            ? $user
            : null;
    }

    /**
     * Whether this model can hold second factors at all.
     *
     * @phpstan-assert-if-true Authenticatable $user
     */
    public static function supports(mixed $user): bool
    {
        return self::tryFrom($user) !== null;
    }
}
