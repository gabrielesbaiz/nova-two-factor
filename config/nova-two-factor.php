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
            'enabled' => env('NOVA_TWO_FACTOR_TOTP_ENABLED', true),

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
            'enabled' => env('NOVA_TWO_FACTOR_WEBAUTHN_ENABLED', true),

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
            'enabled' => env('NOVA_TWO_FACTOR_EMAIL_ENABLED', true),

            'digits' => 6,
            'ttl' => env('NOVA_TWO_FACTOR_EMAIL_TTL', 300),

            // Wrong guesses before the code is burned. Without this you can buy
            // more guesses by asking for a new code.
            'max_attempts' => 5,

            // Server-enforced. The countdown in the UI is cosmetic.
            'resend_after' => env('NOVA_TWO_FACTOR_EMAIL_RESEND_AFTER', 60),

            // Off by default, and it means what it says: the notification is
            // sent on the request rather than handed to a worker. A queue that
            // is not running turns a login into a dead end.
            'queue' => env('NOVA_TWO_FACTOR_EMAIL_QUEUE', false),
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
        // Whether `required` grants any runway at all. Off means the wall
        // appears at the next request, which is right for a panel being locked
        // down today and wrong for one with users who have never been asked.
        'grace_enabled' => env('NOVA_TWO_FACTOR_GRACE_ENABLED', true),

        // `days` — a runway per account, counted from its creation, so every
        //          new joiner gets the same window.
        // `date`  — one deadline everybody shares: "by 1 October".
        'grace_mode' => env('NOVA_TWO_FACTOR_GRACE_MODE', 'days'),

        'grace_days' => env('NOVA_TWO_FACTOR_GRACE_DAYS', 7),

        // ISO-8601 date. Null means grace is measured per user, from signup.
        'enforced_from' => env('NOVA_TWO_FACTOR_ENFORCED_FROM'),

        // `encouraged` only: how long "remind me later" puts the enrollment
        // page away for. Stored against the account, not the browser, so
        // clearing cookies does not restart the nagging.
        'remind_every_days' => env('NOVA_TWO_FACTOR_REMIND_DAYS', 7),

        // How long an *administrator-sent* reminder puts that person off
        // limits, in hours. Not to be confused with `remind_every_days` above,
        // which is the user's own "remind me later" on the enrollment screen —
        // different actor, different screen.
        //
        // Keyed on the recipient, not the sender: the abuse this stops is one
        // address being mailed over and over, and who asked does not change how
        // that lands in their inbox. Zero turns it off.
        'remind_cooldown_hours' => env('NOVA_TWO_FACTOR_REMIND_COOLDOWN', 24),

        // Queue the admin-sent enrollment reminder mail.
        //
        // On by default, unlike the code mail: nobody is waiting at a login
        // screen for a reminder, and an admin reminding forty people should not
        // sit through forty SMTP round-trips. Turn it off if you have no queue
        // worker running.
        'queue_reminders' => env('NOVA_TWO_FACTOR_QUEUE_REMINDERS', true),

        // A Gate ability name. When set, enforcement only applies to users the
        // gate allows — this is the per-role hook, and it deliberately delegates
        // to whatever authorization layer the host app already uses.
        'gate' => env('NOVA_TWO_FACTOR_GATE'),

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
        'ttl' => env('NOVA_TWO_FACTOR_STEP_UP_TTL', 300),

        'protect' => [
            // 'users.destroy' => ['DELETE nova-api/users/*'],
            // 'settings'      => ['* nova-api/settings*'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cookies
    |--------------------------------------------------------------------------
    |
    | `null` takes the application's own answer — `session.secure`, falling back
    | to whether the request looks secure. That fallback is the one that bites:
    | behind a proxy terminating TLS, PHP sees plain HTTP unless `TrustProxies`
    | is configured, and the cookie ships without `Secure` on a site that is
    | plainly HTTPS. Set this to `true` to settle it outright.
    |
    */
    'cookies' => [
        'secure' => env('NOVA_TWO_FACTOR_SECURE_COOKIES'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    |
    | A lockout is the control working: somebody fumbled a code, and a minute
    | later they are back. Several *different* accounts locking inside a few
    | minutes is somebody working through a credential dump — they cannot get
    | in, but under `required` they can keep everybody else out.
    |
    | Off by default (a threshold below 2 disables it). Set one and listen for
    | `Events\LockoutBurstDetected` to page whoever should know.
    |
    */

    'alerts' => [
        'lockout_burst' => [
            // Distinct accounts inside the window before the event fires.
            'accounts' => env('NOVA_TWO_FACTOR_LOCKOUT_ALERT', 0),
            'window_minutes' => env('NOVA_TWO_FACTOR_LOCKOUT_ALERT_WINDOW', 15),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trusted devices
    |--------------------------------------------------------------------------
    */

    'trusted_devices' => [
        'enabled' => env('NOVA_TWO_FACTOR_TRUSTED_DEVICES', true),
        'days' => env('NOVA_TWO_FACTOR_TRUSTED_DEVICE_DAYS', 30),
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
    | The per-user ceilings read from the environment, because a staging box
    | being hammered by its own developer and a production panel want different
    | numbers — and republishing this file with --force must not flatten that.
    |
    */

    'rate_limits' => [
        'challenge' => ['per_user' => env('NOVA_TWO_FACTOR_LIMIT_CHALLENGE', 5), 'per_ip' => 60, 'decay' => 60, 'lockout' => 60, 'lockout_ceiling' => 900],
        'step_up' => ['per_user' => env('NOVA_TWO_FACTOR_LIMIT_STEP_UP', 5), 'per_ip' => 60, 'decay' => 60, 'lockout' => 60, 'lockout_ceiling' => 900],
        // The break-glass path, and so deliberately not the tightest budget
        // here. A recovery code carries ~119 bits: the limit is not what stops
        // it being guessed, it is what stops volume. `per_user` is for input
        // shaped like a code — a person transcribing twenty characters off a
        // card — while `malformed_per_user` catches a script pushing arbitrary
        // bytes. The lockout is flat, never escalating: doubling the wait of
        // somebody who has just lost their phone ends in a support call, which
        // is a weaker check than the code would have been.
        'recovery' => ['per_user' => env('NOVA_TWO_FACTOR_LIMIT_RECOVERY', 10), 'malformed_per_user' => 3, 'per_ip' => 10, 'decay' => 3600, 'lockout' => 3600],
        'enroll' => ['per_user' => env('NOVA_TWO_FACTOR_LIMIT_ENROLL', 10), 'per_ip' => 30, 'decay' => env('NOVA_TWO_FACTOR_LIMIT_ENROLL_DECAY', 600), 'lockout' => 600, 'lockout_ceiling' => 3600],
        'otp_send' => ['per_user' => env('NOVA_TWO_FACTOR_LIMIT_OTP_SEND', 3), 'per_ip' => 10, 'decay' => env('NOVA_TWO_FACTOR_LIMIT_OTP_SEND_DECAY', 900), 'lockout' => 900, 'lockout_ceiling' => 3600],

        // Which bucket a challenge attempt spends.
        //
        // On: a browser that has cleared a challenge for this account before
        // gets a budget of its own, and every other browser shares the
        // account's. That is what keeps somebody with a leaked password from
        // locking the owner out of their own laptop — they arrive on a browser
        // that has cleared nothing, and spend the shared budget instead.
        //
        // Off: every attempt for an account shares one bucket, which is how
        // this behaved before and is simpler to reason about.
        'per_device' => env('NOVA_TWO_FACTOR_PER_DEVICE', true),
        'device_cookie' => 'nova_two_factor_known_device',

        // Administrator-sent reminders. Generous, because chasing a department
        // one row at a time is the intended use — but bounded, because every
        // call sends real mail from your domain, and nothing else here stops
        // one session doing it in a loop.
        'remind' => ['per_user' => env('NOVA_TWO_FACTOR_LIMIT_REMIND', 30), 'per_ip' => 60, 'decay' => 3600, 'lockout' => 1800, 'lockout_ceiling' => 3600],

        // Passkey ceremonies are signatures, not guesses — there is nothing to
        // brute-force, and the authenticator rate-limits the human itself. This
        // is a loop-stopper, not an attempt budget, so it is set far above the
        // counts above and applies wherever a credential is presented.
        'webauthn_per_minute' => env('NOVA_TWO_FACTOR_LIMIT_WEBAUTHN', 30),
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

    'password_confirmation_ttl' => env('NOVA_TWO_FACTOR_PASSWORD_CONFIRMATION_TTL', 900),

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
    | Routes
    |--------------------------------------------------------------------------
    |
    | The path segment this package's own pages live under, below Nova's path.
    |
    | Worth changing in one case: Nova served at the domain root (`nova.path`
    | empty), where the default lands these pages at `/two-factor/*`. If the
    | application already owns a route of that name — its own front-end
    | two-factor flow, say — one of the two silently disappears, because the
    | first registration wins and the loser never appears in the route table.
    |
    */

    'routes' => [
        'prefix' => 'two-factor',
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
    | Settings from the admin page
    |--------------------------------------------------------------------------
    |
    | Off by default: a fresh install should not hand anyone who reaches Nova
    | the ability to weaken two-factor policy. Turn it on and an administrator
    | can change the policy settings — mode, grace, which methods, trusted
    | devices, step-up — from a page instead of a deploy.
    |
    | Anything set in `.env` stays locked: the page shows it read-only rather
    | than editable-but-ignored, because the alternative is an edit the next
    | deploy silently reverts. `enabled` above, this switch itself, the admin
    | gate, the relying party and the table names are never editable there at
    | all — see `Settings\SettingSchema` for the full allow-list and why.
    |
    */

    'settings' => [
        'editable' => env('NOVA_TWO_FACTOR_SETTINGS', false),

        // Longest pause the page will offer, in minutes. A pause stands
        // enforcement down for everyone while leaving the admin pages
        // reachable; it always expires, because a security control switched off
        // on a Friday is the one nobody remembers on Monday.
        'pause_max_minutes' => env('NOVA_TWO_FACTOR_PAUSE_MAX', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Nova integration
    |--------------------------------------------------------------------------
    */

    'nova' => [
        // The admin compliance dashboard: adoption, method mix, failed
        // attempts. Registered with Nova automatically when on.
        'compliance' => [
            'enabled' => env('NOVA_TWO_FACTOR_COMPLIANCE', true),

            // The populations compliance is measured against.
            //
            // Empty means the model behind Nova's guard, which is right for an
            // ordinary single-model app. Name models here when the panel is not
            // for everyone in `users` — counting people who can never sign in
            // to Nova makes the adoption figure comfortably high and wrong:
            //
            //     'models' => [App\Models\Admin::class],
            //
            // Class names only: `config:cache` cannot serialise a closure. To
            // narrow a population, use the tool instead:
            //
            //     NovaTwoFactor::make()->audit(Admin::class, fn ($query) => $query->where('active', true))
            //
            // Also settable as a comma-separated env var, which is what to use
            // when the published config is force-republished on deploy — an
            // edit made here would be lost on the next one.
            'models' => array_filter(array_map(
                'trim',
                explode(',', (string) env('NOVA_TWO_FACTOR_COMPLIANCE_MODELS', '')),
            )),
        ],

        'menu' => [
            // Add the compliance entry to Nova's menu automatically.
            //
            // Turn this off to place it yourself, anywhere you like, and keep
            // the dashboard itself:
            //
            //     Nova::mainMenu(fn ($request) => [
            //         MenuSection::dashboard(Main::class)->icon('chart-bar'),
            //         NovaTwoFactor::menuSection(),          // or ::menuItem()
            //     ]);
            //
            // Both helpers carry the admin gate with them, so a hand-placed
            // entry is no more visible than the automatic one.
            'show' => env('NOVA_TWO_FACTOR_MENU', true),
            // The group the pages sit under. Each page name below it says only
            // what it is — Overview, Settings, Activity — because repeating
            // the subject three times in one menu is what made it unreadable.
            'label' => 'Two-factor',
            'icon' => 'lock-closed',

            // Show the number of users past their grace window on the entry.
            // Costs one chunked pass over the user table per menu render, so
            // turn it off on a very large user base.
            'badge' => env('NOVA_TWO_FACTOR_MENU_BADGE', true),
        ],

        // Gate ability guarding the admin pages: the overview, the settings
        // page, the activity log and the reset action.
        //
        // Named rather than null by default, and deliberately an ability no
        // fresh application defines — `Gate::allows()` denies an ability that
        // does not exist, so the admin surface starts closed and opens only
        // when somebody says who may see it:
        //
        //     Gate::define('nova-two-factor:admin', fn ($user) => $user->isAdmin());
        //
        // These pages carry every account's enrollment status, the addresses
        // behind them, and who is one lost device from a lockout. In most
        // applications everyone on staff can reach Nova, and "everyone on
        // staff" is the wrong audience for that.
        //
        // Set it to `null` to open them to any user who can reach Nova, which
        // is the right answer for a single-administrator panel — but say so
        // explicitly rather than inheriting it.
        'admin_gate' => env('NOVA_TWO_FACTOR_ADMIN_GATE', 'nova-two-factor:admin'),

        // Replace Nova's own two-factor card on its user-security page. Turning
        // this off leaves two competing 2FA UIs on one page, so only do it if
        // you intend to hide Nova's yourself.
        'replace_nova_card' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Interface
    |--------------------------------------------------------------------------
    */

    'ui' => [
        // Show each factor's trade-off on its own line beneath the description
        // — "Cannot be phished.", "Works offline.", "Weakest option — anyone
        // with your inbox has your second factor."
        //
        // On by default: a user choosing a second factor is making a security
        // decision, and these are the sentences that decide it. Turn it off
        // only where that framing is not wanted, accepting that the list then
        // reads as three equivalent options when it is not.
        'show_method_tradeoffs' => env('NOVA_TWO_FACTOR_SHOW_TRADEOFFS', true),
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
            'settings' => 'two_factor_settings',
        ],
    ],

];
