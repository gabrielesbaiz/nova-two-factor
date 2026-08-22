<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Models;

use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Models\Concerns\BelongsToTwoFactorTable;
use Gabrielesbaiz\NovaTwoFactor\Models\Concerns\OwnedByAuthenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Date;

/**
 * @property int $id
 * @property MethodType $type
 * @property string $name
 * @property bool $is_default
 * @property string|null $secret
 * @property string|null $destination
 * @property array<string, mixed>|null $credential
 * @property string|null $destination_hint
 * @property string|null $credential_id
 * @property string|null $credential_id_hash
 * @property int|null $last_timestep
 * @property int $sign_count
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class TwoFactorMethod extends Model
{
    use BelongsToTwoFactorTable;
    use OwnedByAuthenticatable;

    protected static string $tableConfigKey = 'methods';

    /**
     * Guarded rather than fillable: `secret`, `credential` and `destination`
     * must never be settable from request input, and every write goes through
     * an action class anyway.
     */
    protected $guarded = ['id'];

    protected $hidden = ['secret', 'credential', 'credential_id', 'credential_id_hash', 'destination'];

    public function authenticatable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->whereNotNull('confirmed_at');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('confirmed_at');
    }

    public function scopeOfType(Builder $query, MethodType|string $type): Builder
    {
        return $query->where('type', $type instanceof MethodType ? $type->value : $type);
    }

    /**
     * Claim a TOTP timestep, refusing anything already used.
     *
     * A conditional UPDATE rather than read-then-write: the affected-row count
     * tells us whether we won the claim, which makes replay rejection correct
     * under concurrent requests and across application servers, with no lock
     * and no transaction. Returns false when another request got there first.
     */
    public function claimTimestep(int $timestep): bool
    {
        $claimed = $this->newQuery()
            ->whereKey($this->getKey())
            ->where(fn (Builder $query) => $query
                ->whereNull('last_timestep')
                ->orWhere('last_timestep', '<', $timestep))
            ->update([
                'last_timestep' => $timestep,
                'last_used_at' => Date::now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        $this->forceFill(['last_timestep' => $timestep, 'last_used_at' => Date::now()]);
        $this->syncOriginalAttributes(['last_timestep', 'last_used_at']);

        return true;
    }

    /**
     * Claim a WebAuthn signature counter, refusing a regression.
     *
     * A counter of zero is not a regression: every synced passkey (iCloud
     * Keychain, Google Password Manager) reports zero forever, so gating on
     * `> 0` is what stops this check from rejecting the entire platform.
     */
    public function claimSignCount(int $signCount): bool
    {
        // Coalesced, not compared directly: a model created without the column
        // in its attributes reads back null rather than the database default,
        // and `null === 0` would send every synced passkey down the regression
        // path on its first assertion.
        $stored = (int) ($this->sign_count ?? 0);

        if ($signCount === 0 && $stored === 0) {
            $this->touchLastUsed();

            return true;
        }

        $claimed = $this->newQuery()
            ->whereKey($this->getKey())
            ->where('sign_count', '<', $signCount)
            ->update([
                'sign_count' => $signCount,
                'last_used_at' => Date::now(),
            ]);

        if ($claimed === 0) {
            return false;
        }

        $this->forceFill(['sign_count' => $signCount, 'last_used_at' => Date::now()]);
        $this->syncOriginalAttributes(['sign_count', 'last_used_at']);

        return true;
    }

    public function touchLastUsed(): void
    {
        $this->forceFill(['last_used_at' => Date::now()])->save();
    }

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'type' => MethodType::class,
            'is_default' => 'boolean',
            'secret' => 'encrypted',
            'destination' => 'encrypted',
            'credential' => 'encrypted:json',
            'last_timestep' => 'integer',
            'sign_count' => 'integer',
            'confirmed_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }
}
