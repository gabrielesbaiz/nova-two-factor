<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Gabrielesbaiz\NovaTwoFactor\Concerns\HasTwoFactorAuthentication;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Workbench\Database\Factories\AdminFactory;

/**
 * A second authenticatable on its own table and guard.
 *
 * It exists so the polymorphic design is exercised by the suite rather than
 * assumed: plenty of Nova apps authenticate a dedicated Admin model, and a
 * package that only ever sees `App\Models\User` in its tests will ship
 * morph-map and guard bugs.
 */
class Admin extends Authenticatable
{
    use HasFactory;
    use HasTwoFactorAuthentication;
    use Notifiable;

    protected $table = 'admins';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    /**
     * Bound explicitly rather than guessed: Laravel's convention resolves
     * `Database\Factories\...`, which does not exist in a package workbench.
     */
    protected static function newFactory(): Factory
    {
        return AdminFactory::new();
    }
}
