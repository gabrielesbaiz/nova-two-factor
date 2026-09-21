<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Settings;

use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorSetting;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Throwable;

/**
 * The overlay the admin page writes, between the environment and the defaults.
 *
 * Precedence, highest first:
 *
 *   1. this table — what the panel wrote. A deliberate decision made in the UI
 *      is the most recent statement of intent, and it outranks the file.
 *   2. `.env` — the deployment's starting position.
 *   3. `config/nova-two-factor.php` — the shipped defaults.
 *
 * Which makes one thing mandatory rather than nice: where the two disagree, the
 * UI has to say so. Somebody will read `.env`, see `NOVA_TWO_FACTOR_MODE=encouraged`
 * and believe it — so a key whose stored value differs from its environment
 * value is marked as overriding it, and can be put back with one click.
 *
 * Applied by writing into the config repository once per request, so every
 * existing `Config::get('nova-two-factor.…')` call in the package keeps working
 * untouched. That is the whole point: one place to read, one place to override.
 */
class SettingsRepository
{
    protected const CACHE_KEY = 'nova-two-factor:settings';

    /** @var array<string, mixed>|null */
    protected ?array $loaded = null;

    /**
     * What each overridden key was before the overlay replaced it.
     *
     * Needed because config no longer remembers: `apply()` writes the stored
     * value over the environment's, so asking config what a key would fall
     * back to returns the override itself. Clearing a setting has to know the
     * value underneath it — otherwise "restore defaults" looks like switching
     * everything off.
     *
     * @var array<string, mixed>
     */
    protected array $baseline = [];

    /**
     * Push the overlay into config.
     *
     * Applied over the environment, not beside it: an administrator who changed
     * the mode in the panel expects the panel to be right, and a deploy that
     * silently restored the old value would be the surprise — in the other
     * direction from the one this used to guard against.
     */
    public function apply(): void
    {
        foreach ($this->stored() as $key => $value) {
            if (! SettingSchema::has($key)) {
                continue;
            }

            // Only the first time: `apply()` can run after a write in the same
            // request, and recording again would capture the override itself as
            // the value underneath it.
            if (! array_key_exists($key, $this->baseline)) {
                $this->baseline[$key] = Config::get('nova-two-factor.'.$key);
            }

            Config::set('nova-two-factor.'.$key, $value);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function stored(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        // Cached because this runs on every request, including the ones that
        // never look at a setting. Rescued because it also runs before the
        // table exists — on a fresh install, and during the migration that
        // creates it — and a package that fatals before `migrate` can finish is
        // a package nobody can install.
        $this->loaded = Cache::rememberForever(self::CACHE_KEY, function (): array {
            try {
                return TwoFactorSetting::query()->pluck('value', 'key')->all();
            } catch (Throwable) {
                return [];
            }
        });

        return $this->loaded;
    }

    /**
     * Write one setting, or remove it to fall back to the default.
     *
     * Returns the previous effective value, which is what the audit row needs:
     * "changed to required" is half a sentence.
     */
    public function set(string $key, mixed $value, ?Authenticatable $by = null): mixed
    {
        if (! SettingSchema::has($key)) {
            return null;
        }

        $previous = Config::get('nova-two-factor.'.$key);

        // The first override is the moment the base value is still in config;
        // after that, config holds the override and the original is gone.
        // Recorded here as well as in `apply()`, because a write in the same
        // request never passes through `apply()` at all.
        if (! array_key_exists($key, $this->baseline)) {
            $this->baseline[$key] = $previous;
        }

        if ($value === null) {
            TwoFactorSetting::query()->where('key', $key)->delete();
        } else {
            TwoFactorSetting::query()->updateOrCreate(['key' => $key], [
                'value' => $value,
                'updated_by_type' => $by?->getMorphClass(),
                'updated_by_id' => $by?->getAuthIdentifier(),
            ]);
        }

        $this->flush();

        // Reflected immediately so the response describes the world the caller
        // just created, rather than the one it replaced.
        Config::set('nova-two-factor.'.$key, $value ?? $this->baseline($key));

        return $previous;
    }

    /**
     * Raw state for one key: what is in force, what the environment says, and
     * whether the panel is currently overriding it.
     *
     * @return array{value: mixed, stored: bool, from_env: bool, overrides_env: bool, env_value: mixed}
     */
    public function state(string $key): array
    {
        $stored = array_key_exists($key, $this->stored());
        $fromEnv = SettingSchema::isLocked($key);
        $envValue = SettingSchema::environmentValue($key);

        return [
            'value' => Config::get('nova-two-factor.'.$key),
            'stored' => $stored,
            'from_env' => $fromEnv,
            // The chip the UI shows. Not a warning — an explanation for whoever
            // reads the file later and finds it disagreeing with the panel.
            'overrides_env' => $stored && $fromEnv && $this->stored()[$key] !== $envValue,
            'env_value' => $envValue,
        ];
    }

    /**
     * The value a key would have with no override: the environment's, or the
     * shipped default underneath it.
     */
    public function baseline(string $key): mixed
    {
        if (array_key_exists($key, $this->baseline)) {
            return $this->baseline[$key];
        }

        // Not overridden, so what config holds is already the base value.
        return Config::get('nova-two-factor.'.$key);
    }

    public function flush(): void
    {
        $this->loaded = null;

        Cache::forget(self::CACHE_KEY);
    }
}
