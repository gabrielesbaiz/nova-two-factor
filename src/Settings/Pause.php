<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Settings;

use Carbon\CarbonInterface;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorSetting;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Enforcement, temporarily off.
 *
 * The state between "on" and "uninstalled": nobody is challenged, nobody is
 * blocked, and the admin pages keep working — which is the point. An
 * administrator who needs to change the rules has to be able to reach the page
 * that changes them, and a misconfigured second factor is exactly the situation
 * where they cannot.
 *
 * Three properties it must have, and each one is a rule someone learned the
 * hard way:
 *
 *   - **It expires by itself.** A pause with no end is a security control
 *     switched off on a Friday afternoon and remembered on Monday. Every pause
 *     carries an expiry, and an open-ended one is still capped.
 *   - **It survives a deploy.** Stored in the database, not the cache, so a
 *     cache flush mid-incident does not silently re-arm the gate under someone
 *     who is halfway through fixing it.
 *   - **It is attributed.** Who, why, until when. A pause nobody can explain is
 *     indistinguishable from an attacker who reached the settings page.
 *
 * It deliberately does not clear anyone's verified session: pausing is not a
 * logout, and turning it off again must not strand people mid-task.
 */
class Pause
{
    protected const KEY = 'system.paused_until';

    protected const META = 'system.paused_meta';

    protected const CACHE_KEY = 'nova-two-factor:pause';

    /**
     * Whether enforcement is currently standing down.
     *
     * Read on every guarded request, so it is cached — but only for a minute.
     * The window matters in both directions: a resume that takes a minute to
     * take effect is tolerable; a pause that outlives its expiry by an hour
     * because of a long TTL is the failure this class exists to prevent.
     */
    public function active(): bool
    {
        return $this->until()?->isFuture() === true;
    }

    public function until(): ?CarbonInterface
    {
        $raw = $this->read()[self::KEY] ?? null;

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return rescue(fn (): CarbonInterface => Date::parse($raw), null, report: false);
    }

    /**
     * @return array{until: string|null, by: string|null, reason: string|null, minutes_left: int|null}
     */
    public function state(): array
    {
        $until = $this->until();
        $meta = $this->read()[self::META] ?? [];

        if ($until === null || $until->isPast()) {
            return ['until' => null, 'by' => null, 'reason' => null, 'minutes_left' => null];
        }

        return [
            'until' => $until->toIso8601String(),
            'by' => is_array($meta) ? ($meta['by'] ?? null) : null,
            'reason' => is_array($meta) ? ($meta['reason'] ?? null) : null,
            'minutes_left' => (int) ceil(Date::now()->diffInSeconds($until) / 60),
        ];
    }

    /**
     * Stand enforcement down for a number of minutes.
     *
     * The ceiling is configuration, not a constant: "until I resume" is a real
     * need during a migration, and a package that refuses it just gets worked
     * around with `NOVA_TWO_FACTOR_ENABLED=false`, which is worse — that one
     * leaves no banner, no expiry and no audit row.
     */
    public function start(int $minutes, ?string $reason, ?Authenticatable $by = null): CarbonInterface
    {
        $ceiling = max(1, (int) Config::get('nova-two-factor.settings.pause_max_minutes', 120));
        $until = Date::now()->addMinutes(max(1, min($minutes, $ceiling)));

        $this->write(self::KEY, $until->toIso8601String());
        $this->write(self::META, [
            'by' => $by === null ? null : (string) ($by->getAttribute('name') ?? $by->getAuthIdentifier()),
            'by_id' => $by?->getAuthIdentifier(),
            'reason' => $reason === null || trim($reason) === '' ? null : mb_substr(trim($reason), 0, 500),
        ]);

        return $until;
    }

    public function resume(): void
    {
        $this->write(self::KEY, null);
        $this->write(self::META, null);
    }

    /**
     * @return array<string, mixed>
     */
    protected function read(): array
    {
        return Cache::remember(self::CACHE_KEY, 60, function (): array {
            try {
                return TwoFactorSetting::query()
                    ->whereIn('key', [self::KEY, self::META])
                    ->pluck('value', 'key')
                    ->all();
            } catch (Throwable) {
                // Before the table exists, nothing is paused.
                return [];
            }
        });
    }

    protected function write(string $key, mixed $value): void
    {
        if ($value === null) {
            TwoFactorSetting::query()->where('key', $key)->delete();
        } else {
            TwoFactorSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget(self::CACHE_KEY);
    }
}
