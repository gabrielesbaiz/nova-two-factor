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
 * @property string $code_hash
 * @property \Illuminate\Support\Carbon|null $used_at
 * @property string|null $used_ip
 */
class TwoFactorRecoveryCode extends Model
{
    use BelongsToTwoFactorTable;
    use OwnedByAuthenticatable;

    protected static string $tableConfigKey = 'recovery_codes';

    protected $guarded = ['id'];

    protected $hidden = ['code_hash'];

    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeUnused(Builder $query): Builder
    {
        return $query->whereNull('used_at');
    }

    /**
     * Spend this code, refusing a second use.
     *
     * Conditional UPDATE again: two requests racing the same code must not both
     * succeed, and the affected-row count settles it without a transaction.
     */
    public function consume(?string $ip = null): bool
    {
        $consumed = $this->newQuery()
            ->whereKey($this->getKey())
            ->whereNull('used_at')
            ->update([
                'used_at' => Date::now(),
                'used_ip' => $ip,
            ]);

        return $consumed === 1;
    }

    protected function casts(): array
    {
        return [
            'used_at' => 'datetime',
        ];
    }
}
