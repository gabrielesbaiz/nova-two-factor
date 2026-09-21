<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

/**
 * The Eloquent model behind the guard Nova authenticates with.
 *
 * Resolved from the auth configuration rather than hardcoded to `App\Models\User`:
 * a host may run Nova on its own guard, and the compliance figures have to count
 * the users Nova actually signs in — counting a different table would produce an
 * adoption percentage that is confidently wrong.
 */
final class UserModel
{
    /**
     * @return class-string<Model>|null
     */
    public static function resolve(?string $guard = null): ?string
    {
        $guard ??= (string) (Config::get('nova.guard') ?: Config::get('auth.defaults.guard'));

        $provider = Config::get("auth.guards.{$guard}.provider");

        if (! is_string($provider) || $provider === '') {
            return null;
        }

        $class = Config::get("auth.providers.{$provider}.model");

        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class;
    }
}
