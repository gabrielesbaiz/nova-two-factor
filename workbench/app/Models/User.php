<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Gabrielesbaiz\NovaTwoFactor\Concerns\HasTwoFactorAuthentication;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Workbench\Database\Factories\UserFactory;

class User extends Authenticatable
{
    use HasFactory;
    use HasTwoFactorAuthentication;
    use Notifiable;

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Bound explicitly rather than guessed: Laravel's convention resolves
     * `Database\Factories\...`, which does not exist in a package workbench.
     */
    protected static function newFactory(): Factory
    {
        return UserFactory::new();
    }
}
