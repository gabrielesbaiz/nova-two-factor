<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Models;

use Gabrielesbaiz\NovaTwoFactor\Models\Concerns\BelongsToTwoFactorTable;
use Gabrielesbaiz\NovaTwoFactor\Models\Concerns\OwnedByAuthenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Date;

/**
 * @property int $id
 * @property string $token_hash
 * @property string|null $name
 * @property string $client_hash
 * @property string|null $ip
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon $expires_at
 */
class TwoFactorTrustedDevice extends Model
{
    use BelongsToTwoFactorTable;
    use OwnedByAuthenticatable;

    protected static string $tableConfigKey = 'trusted_devices';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'client_hash'];

    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('expires_at', '>', Date::now());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', Date::now());
    }

    public function hasExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
