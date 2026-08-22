<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Models;

use Gabrielesbaiz\NovaTwoFactor\Enums\ChallengePurpose;
use Gabrielesbaiz\NovaTwoFactor\Models\Concerns\BelongsToTwoFactorTable;
use Gabrielesbaiz\NovaTwoFactor\Models\Concerns\OwnedByAuthenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Date;

/**
 * A pending out-of-band code (email OTP). TOTP and WebAuthn need no row here —
 * their proof is computed, not delivered.
 *
 * @property int $id
 * @property int $method_id
 * @property ChallengePurpose $purpose
 * @property string $code_hash
 * @property int $attempts
 * @property \Illuminate\Support\Carbon $sent_at
 * @property \Illuminate\Support\Carbon $expires_at
 * @property \Illuminate\Support\Carbon|null $consumed_at
 */
class TwoFactorChallenge extends Model
{
    use BelongsToTwoFactorTable;
    use OwnedByAuthenticatable;

    protected static string $tableConfigKey = 'challenges';

    protected $guarded = ['id'];

    protected $hidden = ['code_hash'];

    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function method(): BelongsTo
    {
        return $this->belongsTo(TwoFactorMethod::class, 'method_id');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')->where('expires_at', '>', Date::now());
    }

    public function isLive(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }

    /**
     * Mark this code as spent, refusing a second use.
     */
    public function consume(): bool
    {
        return $this->newQuery()
            ->whereKey($this->getKey())
            ->whereNull('consumed_at')
            ->update(['consumed_at' => Date::now()]) === 1;
    }

    /**
     * Record a wrong guess and report whether the budget is now exhausted.
     * When it is, the caller burns the row — otherwise a user could buy fresh
     * attempts simply by asking for another code.
     */
    public function registerFailedAttempt(int $maxAttempts): bool
    {
        $this->increment('attempts');

        return $this->attempts >= $maxAttempts;
    }

    public function canResend(int $resendAfterSeconds): bool
    {
        return $this->sent_at->addSeconds($resendAfterSeconds)->isPast();
    }

    protected function casts(): array
    {
        return [
            'purpose' => ChallengePurpose::class,
            'attempts' => 'integer',
            'sent_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }
}
