<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Enums\EnforcementMode;

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Turning this off removes the challenge, the enforcement middleware and the
    | management UI. Enrolled methods are left untouched, so it is safe to use as
    | a break-glass switch and turn back on.
    |
    */

    'enabled' => env('NOVA_TWO_FACTOR_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Factor methods
    |--------------------------------------------------------------------------
    |
    | Each method can be disabled independently. Disabling one hides it from
    | enrollment but still honours already-enrolled instances at the challenge,
    | so nobody is locked out by a config change.
    |
    */

    'methods' => [

        'totp' => [
            'enabled' => true,

            // Bytes of entropy in the shared secret. 32 => a 160-bit secret,
            // which is what RFC 4226 recommends. Floor is 16.
            'secret_bytes' => 32,

            'digits' => 6,
            'period' => 30,

            // Accepted drift, in periods, either side of now. 1 => +/-30s.
            // Every accepted timestep is persisted, so a code is never
            // accepted twice regardless of this value.
            'window' => 1,

            // How long an unconfirmed enrollment secret stays valid. The secret
            // lives in the cache, not the database, until it is confirmed.
            'enrollment_ttl' => 900,
        ],

        'webauthn' => [
            'enabled' => true,

            'relying_party' => [
                // Defaults to the host of config('app.url'). Never derived from
                // the request Host header, which is attacker-controlled.
                'id' => env('NOVA_TWO_FACTOR_WEBAUTHN_RP_ID'),
                'name' => env('NOVA_TWO_FACTOR_WEBAUTHN_RP_NAME'),
            ],

            // Exact scheme+host+port matches. Defaults to [config('app.url')].
            'origins' => array_filter(explode(',', (string) env('NOVA_TWO_FACTOR_WEBAUTHN_ORIGINS', ''))),

            // 'discouraged' | 'preferred' | 'required'. Always forced to
            // 'required' for step-up, whatever is configured here.
            'user_verification' => 'preferred',

            // Discoverable credentials, so the challenge screen can offer
            // passkey autofill through conditional mediation.
            'resident_key' => 'preferred',

            'attestation' => 'none',
            'allowed_aaguids' => [],

            // Seconds a registration or assertion challenge stays valid.
            'timeout' => 60,

            // What to do when an authenticator reports a signature counter at
            // or below the stored one: 'reject' | 'reject_and_disable' | 'log'.
            // Counters of zero are exempt — every synced passkey reports zero.
            'on_counter_regression' => 'reject',
        ],

        'email' => [
            'enabled' => true,

            'digits' => 6,
            'ttl' => 300,

            // Wrong guesses before the code is burned. Without this you can buy
            // more guesses by asking for a new code.
            'max_attempts' => 5,

            // Server-enforced. The countdown in the UI is cosmetic.
            'resend_after' => 60,

            'queue' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Recovery codes
    |--------------------------------------------------------------------------
    |
    | Hashed individually with SHA-256 behind a unique index, and single-use. A
    | code satisfies one challenge; it never removes a factor.
    |
    */

    'recovery_codes' => [
        'count' => 8,

        // Characters per half. Codes are rendered as xxxxxxxxxx-xxxxxxxxxx.
        'length' => 10,

        // Nag to regenerate at or below this many unused codes.
        'warn_at' => 3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Enforcement
    |--------------------------------------------------------------------------
    |
    | 'optional'    nothing is required
    | 'encouraged'  a dismissible prompt, no blocking
    | 'required'    Nova is unreachable until enrolled, once grace has expired
    |
    */

    'enforcement' => [
        'mode' => env('NOVA_TWO_FACTOR_MODE', EnforcementMode::Optional->value),

        // Days from `enforced_from`, or from the user's created_at when that is
        // null, before enrollment actually blocks.
        'grace_days' => 7,

        // ISO-8601 date. Null means grace is measured per user, from signup.
        'enforced_from' => env('NOVA_TWO_FACTOR_ENFORCED_FROM'),

        // A Gate ability name. When set, enforcement only applies to users the
        // gate allows — this is the per-role hook, and it deliberately delegates
        // to whatever authorization layer the host app already uses.
        'gate' => null,

        // Extra request patterns to leave open, merged with the built-in list
        // (which is derived from config('nova.path') at runtime, never
        // hardcoded). Wildcards are supported.
        'except' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Step-up re-authentication
    |--------------------------------------------------------------------------
    |
    | Each key is a scope name; each value is a list of "METHOD pattern" globs.
    | A grant is bound to its scope, so proving yourself for one never satisfies
    | another.
    |
    */

    'step_up' => [
        'ttl' => 300,

        'protect' => [
            // 'users.destroy' => ['DELETE nova-api/users/*'],
            // 'settings'      => ['* nova-api/settings*'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted devices
    |--------------------------------------------------------------------------
    */

    'trusted_devices' => [
        'enabled' => true,
        'days' => 30,
        'cookie' => 'nova_two_factor_device',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits
    |--------------------------------------------------------------------------
    |
    | Every limiter is keyed on both the user and the IP: an IP-only limit lets a
    | botnet spread an attack on one account, and a user-only limit lets one host
    | hammer every account.
    |
    */

    'rate_limits' => [
        'challenge' => ['per_user' => 5, 'per_ip' => 20, 'decay' => 60, 'lockout' => 60, 'lockout_ceiling' => 900],
        'step_up' => ['per_user' => 5, 'per_ip' => 20, 'decay' => 60, 'lockout' => 60, 'lockout_ceiling' => 900],
        'recovery' => ['per_user' => 3, 'per_ip' => 10, 'decay' => 3600, 'lockout' => 3600, 'lockout_ceiling' => 3600],
        'enroll' => ['per_user' => 10, 'per_ip' => 30, 'decay' => 600, 'lockout' => 600, 'lockout_ceiling' => 600],
        'otp_send' => ['per_user' => 3, 'per_ip' => 10, 'decay' => 900, 'lockout' => 900, 'lockout_ceiling' => 900],
    ],

    /*
    |--------------------------------------------------------------------------
    | Password confirmation
    |--------------------------------------------------------------------------
    |
    | Seconds a password confirmation stays fresh for destructive operations
    | (removing a method, revealing or regenerating recovery codes, revoking
    | devices).
    |
    */

    'password_confirmation_ttl' => 900,

    /*
    |--------------------------------------------------------------------------
    | Audit log
    |--------------------------------------------------------------------------
    |
    | Codes, secrets, credentials and destinations are never written here. A
    | guard throws outside production if a payload looks like it contains one.
    |
    */

    'audit' => [
        'enabled' => true,
        'queue' => false,
        'prune_after_days' => 365,
    ],

    /*
    |--------------------------------------------------------------------------
    | Fortify interoperability
    |--------------------------------------------------------------------------
    |
    | Nova keeps Fortify's own two-factor challenge in its login pipeline while
    | the `twoFactorAuthentication` feature is enabled — and that feature has to
    | stay enabled for Nova's user-security card to render. Anyone whose row
    | still carries a `users.two_factor_secret` is therefore diverted to
    | Fortify's challenge before this package sees the request.
    |
    | Superseding it hands the challenge back to this package, which runs it
    | after authentication through the Nova middleware groups. Leave it on
    | unless you genuinely want Fortify to own the Nova login challenge — the
    | alternative is deleting `users.two_factor_*`, which is destructive when a
    | second guard (a customer-facing front end, say) has its own Fortify
    | two-factor living on those same columns.
    |
    */

    'fortify' => [
        'supersede_challenge' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Nova integration
    |--------------------------------------------------------------------------
    */

    'nova' => [
        'menu' => [
            'show' => true,
            'label' => 'Security',
            'icon' => 'lock-closed',
        ],

        // Gate ability guarding the admin compliance page, the resources and
        // the reset action. Null means nobody but a Nova::auth-passing user.
        'admin_gate' => null,

        // Replace Nova's own two-factor card on its user-security page. Turning
        // this off leaves two competing 2FA UIs on one page, so only do it if
        // you intend to hide Nova's yourself.
        'replace_nova_card' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    */

    'database' => [
        'connection' => null,

        'tables' => [
            'methods' => 'two_factor_methods',
            'recovery_codes' => 'two_factor_recovery_codes',
            'trusted_devices' => 'two_factor_trusted_devices',
            'challenges' => 'two_factor_challenges',
            'audits' => 'two_factor_audits',
        ],
    ],

];
