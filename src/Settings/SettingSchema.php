<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Settings;

/**
 * The settings an administrator may change from the panel, and nothing else.
 *
 * An allow-list, not a deny-list. The endpoint writes only keys named here, so
 * a crafted request cannot reach `database.connection` or `nova.admin_gate` —
 * and the line is drawn at blast radius rather than convenience:
 *
 *   - policy (who must enrol, by when, with what) is editable;
 *   - anything that decides *where trust comes from* (the relying party), *who
 *     may administer this* (the gate), or *what the package even is* (enabled,
 *     tables, the Fortify seam) is deploy-time only.
 *
 * Editing the gate from the page the gate protects is privilege escalation with
 * extra steps; a typo in the relying-party id silently invalidates every passkey
 * in the estate. Neither belongs behind a form field.
 */
final class SettingSchema
{
    /**
     * key => [type, env, section, modes, requires, min, max, options]
     *
     * `env` is the variable that supplies the key's starting value. The panel
     * can still change it — a decision made in the UI is the most recent
     * statement of intent — but where the two disagree the UI says so, and
     * offers to put the environment's value back.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'enforcement.mode' => [
                'type' => 'enum',
                'env' => 'NOVA_TWO_FACTOR_MODE',
                'section' => 'enforcement',
                'options' => ['optional', 'encouraged', 'required'],
            ],
            // `modes` is the list this setting has any effect in. A grace
            // window only means something where something blocks, and a
            // reminder interval only where there is a reminder — showing them
            // otherwise invites configuring a number that does nothing, which
            // is worse than not offering it: the admin walks away believing
            // they changed the policy.
            'enforcement.grace_enabled' => [
                'type' => 'bool',
                'env' => 'NOVA_TWO_FACTOR_GRACE_ENABLED',
                'section' => 'enforcement',
                'modes' => ['required'],
            ],
            'enforcement.grace_mode' => [
                'type' => 'enum',
                'env' => 'NOVA_TWO_FACTOR_GRACE_MODE',
                'section' => 'enforcement',
                'modes' => ['required'],
                'options' => ['days', 'date'],
                // Shown only once grace is switched on: a choice between two
                // ways of measuring something that is not being measured is a
                // question with no answer.
                'requires' => ['enforcement.grace_enabled' => true],
            ],
            'enforcement.grace_days' => [
                'type' => 'int',
                'env' => 'NOVA_TWO_FACTOR_GRACE_DAYS',
                'section' => 'enforcement',
                'modes' => ['required'],
                'requires' => ['enforcement.grace_enabled' => true, 'enforcement.grace_mode' => 'days'],
                'min' => 0,
                'max' => 365,
            ],
            'enforcement.enforced_from' => [
                'type' => 'date',
                'env' => 'NOVA_TWO_FACTOR_ENFORCED_FROM',
                'section' => 'enforcement',
                'modes' => ['required'],
                'requires' => ['enforcement.grace_enabled' => true, 'enforcement.grace_mode' => 'date'],
            ],
            'enforcement.remind_every_days' => [
                'type' => 'int',
                'env' => 'NOVA_TWO_FACTOR_REMIND_DAYS',
                'section' => 'enforcement',
                'modes' => ['encouraged'],
                'min' => 1,
                'max' => 365,
            ],

            'methods.webauthn.enabled' => [
                'type' => 'bool',
                'env' => 'NOVA_TWO_FACTOR_WEBAUTHN_ENABLED',
                'section' => 'methods',
            ],
            'methods.totp.enabled' => [
                'type' => 'bool',
                'env' => 'NOVA_TWO_FACTOR_TOTP_ENABLED',
                'section' => 'methods',
            ],
            'methods.email.enabled' => [
                'type' => 'bool',
                'env' => 'NOVA_TWO_FACTOR_EMAIL_ENABLED',
                'section' => 'methods',
            ],
            'methods.email.ttl' => [
                'type' => 'int',
                'env' => 'NOVA_TWO_FACTOR_EMAIL_TTL',
                'section' => 'methods',
                'requires' => ['methods.email.enabled' => true],
                'min' => 60,
                'max' => 3600,
            ],
            'methods.email.resend_after' => [
                'type' => 'int',
                'env' => 'NOVA_TWO_FACTOR_EMAIL_RESEND_AFTER',
                'section' => 'methods',
                'requires' => ['methods.email.enabled' => true],
                'min' => 15,
                'max' => 600,
            ],

            'trusted_devices.enabled' => [
                'type' => 'bool',
                'env' => 'NOVA_TWO_FACTOR_TRUSTED_DEVICES',
                'section' => 'convenience',
            ],
            'trusted_devices.days' => [
                'type' => 'int',
                'env' => 'NOVA_TWO_FACTOR_TRUSTED_DEVICE_DAYS',
                'section' => 'convenience',
                'requires' => ['trusted_devices.enabled' => true],
                'min' => 1,
                'max' => 365,
            ],
            'step_up.ttl' => [
                'type' => 'int',
                'env' => 'NOVA_TWO_FACTOR_STEP_UP_TTL',
                'section' => 'convenience',
                'min' => 30,
                'max' => 3600,
            ],

            'ui.show_method_tradeoffs' => [
                'type' => 'bool',
                'env' => 'NOVA_TWO_FACTOR_SHOW_TRADEOFFS',
                'section' => 'interface',
            ],
            'nova.menu.badge' => [
                'type' => 'bool',
                'env' => 'NOVA_TWO_FACTOR_MENU_BADGE',
                'section' => 'interface',
            ],
        ];
    }

    /**
     * The three method switches, at least one of which has to stay on.
     *
     * @return array<int, string>
     */
    public static function methodKeys(): array
    {
        return [
            'methods.webauthn.enabled',
            'methods.totp.enabled',
            'methods.email.enabled',
        ];
    }

    /**
     * What a settings key is called in a sentence.
     *
     * The audit log reads `enforcement.remind_every_days: 7 → 2` without it,
     * which is a dotted path from a config file shown to somebody who has
     * never opened one.
     */
    public static function label(string $key): string
    {
        return match ($key) {
            'enforcement.mode' => __('Mode'),
            'enforcement.grace_enabled' => __('Grace period'),
            'enforcement.grace_mode' => __('Measured as'),
            'enforcement.grace_days' => __('Days from when the account was created'),
            'enforcement.enforced_from' => __('Everyone must comply by'),
            'enforcement.remind_every_days' => __('Remind again after'),
            'methods.webauthn.enabled' => __('Passkeys'),
            'methods.totp.enabled' => __('Authenticator app'),
            'methods.email.enabled' => __('Email codes'),
            'methods.email.ttl' => __('Email code lifetime'),
            'methods.email.resend_after' => __('Resend allowed after'),
            'trusted_devices.enabled' => __('Remember this device'),
            'trusted_devices.days' => __('Remembered for'),
            'step_up.ttl' => __('Step-up lasts'),
            'ui.show_method_tradeoffs' => __('Show what each method costs'),
            'nova.menu.badge' => __('Overdue count on the menu'),
            default => $key,
        };
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    /**
     * The environment's own value for a key, uncast.
     *
     * Kept so the UI can say what the file says, and offer to go back to it —
     * "reset" has to mean something specific, and this is the something.
     */
    public static function environmentValue(string $key): mixed
    {
        $variable = self::get($key)['env'] ?? null;

        if (! is_string($variable)) {
            return null;
        }

        // `??` has already dealt with the null case; `getenv()` is the one that
        // signals absence with false.
        $raw = $_ENV[$variable] ?? $_SERVER[$variable] ?? getenv($variable);

        if ($raw === false) {
            return null;
        }

        return match (self::get($key)['type'] ?? 'string') {
            'bool' => filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
            'int' => (int) $raw,
            default => (string) $raw,
        };
    }

    /**
     * Whether the deployment has set this key in the environment.
     *
     * `getenv()` and `$_ENV` both, because a key set in a real environment (a
     * container, a systemd unit) never reaches `$_ENV` on some SAPIs, and a key
     * present only in `.env` never reaches `getenv()` once the config is
     * cached. Missing either way round produces the bug this exists to prevent.
     */
    public static function isLocked(string $key): bool
    {
        $variable = self::get($key)['env'] ?? null;

        if (! is_string($variable)) {
            return false;
        }

        return getenv($variable) !== false
            || array_key_exists($variable, $_ENV)
            || array_key_exists($variable, $_SERVER);
    }
}
