<p align="center">
    <img src="art/nova-two-factor-logo.png" alt="Nova Two-Factor" width="600">
</p>

# Nova Two-Factor

Two-factor authentication for Laravel Nova 5: authenticator apps, passkeys, email codes and recovery codes, with enforcement policies, step-up re-authentication, trusted devices and admin oversight.

[![Latest version](https://img.shields.io/packagist/v/gabrielesbaiz/nova-two-factor.svg?style=flat-square)](https://packagist.org/packages/gabrielesbaiz/nova-two-factor)
[![PHP](https://img.shields.io/packagist/dependency-v/gabrielesbaiz/nova-two-factor/php?style=flat-square)](composer.json)
[![Laravel](https://img.shields.io/packagist/dependency-v/gabrielesbaiz/nova-two-factor/illuminate%2Fsupport?style=flat-square&label=laravel)](composer.json)
[![Downloads](https://img.shields.io/packagist/dt/gabrielesbaiz/nova-two-factor.svg?style=flat-square)](https://packagist.org/packages/gabrielesbaiz/nova-two-factor)
[![Stars](https://img.shields.io/github/stars/gabrielesbaiz/nova-two-factor?style=flat-square&logo=github)](https://github.com/gabrielesbaiz/nova-two-factor/stargazers)
[![Sponsor](https://img.shields.io/github/sponsors/gabrielesbaiz?style=flat-square&label=sponsor&logo=github)](https://github.com/sponsors/gabrielesbaiz)

> [!TIP]
> Every screen, in light and dark:
> [screenshots](https://github.com/gabrielesbaiz/nova-two-factor/blob/main/SCREENSHOTS.md).

> [!IMPORTANT]
> A ⭐ costs you nothing and helps other developers find this package.
> [Sponsoring](https://github.com/sponsors/gabrielesbaiz) keeps it compatible
> with every new Laravel release.

> [!CAUTION]
> **Upgrading from 1.x?** Read [UPGRADE.md](UPGRADE.md) first. 1.x had critical
> vulnerabilities, including unauthenticated endpoints and TOTP secrets sent to
> a third-party QR service by default. **Treat every 1.x secret as compromised**
> and have users re-enrol.

## Contents

- [Introduction](#introduction)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Managing Two-Factor Authentication](#managing-two-factor-authentication)
- [Authentication Methods](#authentication-methods)
- [Enforcement](#enforcement)
- [Step-Up Authentication](#step-up-authentication)
- [Administration](#administration)
- [Security](#security)
- [Extending](#extending)
- [Troubleshooting](#troubleshooting)
- [Artisan Commands](#artisan-commands)
- [Testing](#testing)
- [Contributing](#contributing)
- [Security Vulnerabilities](#security-vulnerabilities)
- [Credits](#credits)
- [Support This Package](#support-this-package)
- [Disclaimer](#disclaimer)
- [License](#license)

## Introduction

Nova Two-Factor adds a complete second-factor implementation to Laravel Nova:
authenticator applications, passkeys, email codes and recovery codes, backed by
an enforcement policy, step-up re-authentication for dangerous actions, trusted
devices, an audit trail, and three administration pages.

Nova already ships two-factor authentication of its own, through Laravel
Fortify. If authenticator applications and recovery codes for users who opt in
are all you need, you should use it:

```php
use Laravel\Fortify\Features;

Nova::fortify()->features([
    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]),
]);
```

This package exists for what that cannot do:

| | Nova + Fortify | This package |
|---|:---:|:---:|
| Authenticator apps (TOTP) | ✅ | ✅ |
| Recovery codes | ✅ | ✅ |
| **Passkeys / WebAuthn** | — | ✅ |
| **Email one-time codes** | — | ✅ |
| **More than one method per user** | — | ✅ |
| **Mandatory enrollment, with a grace period** | — | ✅ |
| **Per-role targeting** | — | ✅ |
| **Step-up re-auth for sensitive actions** | — | ✅ |
| **Trusted devices** | — | ✅ |
| **Audit trail** | — | ✅ |
| **Admin oversight and compliance reporting** | — | ✅ |
| **TOTP replay protection** | partial | ✅ |
| Recovery codes hashed individually | — | ✅ |

The last two rows are implementation differences rather than missing features,
and both are explained in [DESIGN.md](DESIGN.md).

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- Nova 5.7+
- `https` in production. Passkeys require a secure context; `localhost` is exempt.

## Installation

Install the package, publish its migrations and configuration, and publish the
assets used by the screens that render outside Nova:

```bash
composer require gabrielesbaiz/nova-two-factor
php artisan vendor:publish --tag=nova-two-factor-migrations
php artisan migrate
php artisan vendor:publish --tag=nova-two-factor-config
php artisan vendor:publish --tag=nova-two-factor-assets
```

Next, add the `HasTwoFactorAuthentication` trait to each authenticatable model
that may hold a second factor:

```php
use Gabrielesbaiz\NovaTwoFactor\Concerns\HasTwoFactorAuthentication;

class User extends Authenticatable
{
    use HasTwoFactorAuthentication;
}
```

Then register the tool in your `NovaServiceProvider`:

```php
use Gabrielesbaiz\NovaTwoFactor\NovaTwoFactor;

public function tools(): array
{
    return [
        NovaTwoFactor::make(),
    ];
}
```

Finally, you should ensure Fortify's `updatePasswords` and
`twoFactorAuthentication` features remain enabled. This package renders its own
card on Nova's user security page, and Nova only registers that page when at
least one security feature is active. The `updatePasswords` feature registers
`nova.password.confirm`, which guards every destructive route in this package:

```php
use Laravel\Fortify\Features;

Nova::fortify()->features([
    Features::updatePasswords(),
    Features::twoFactorAuthentication(['confirm' => false, 'confirmPassword' => false]),
]);
```

You should set `confirm` and `confirmPassword` to `false`. Enrollment
confirmation is handled by this package rather than by Fortify's own endpoint.

> [!IMPORTANT]
> This is a requirement, not a recommendation. With every Fortify security
> feature disabled, `/user-security` does not exist: it returns 404 or 403, and
> the enrollment screen's links dead-end there.

With the feature enabled, Fortify's `RedirectIfTwoFactorAuthenticatable` would
divert any user holding a `users.two_factor_secret` to Fortify's own challenge.
This package supersedes that action, so the challenge stays here, where the
method choice, trusted devices and the audit trail live. Credential validation
is untouched. You may turn this off if you would rather Fortify owned the Nova
login challenge:

```php
// config/nova-two-factor.php
'fortify' => [
    'supersede_challenge' => true,
],
```

> [!WARNING]
> With `supersede_challenge` off and a `two_factor_secret` on the row, users
> reach Fortify's challenge and never see this package's methods — a login that
> works and logs nothing. `doctor` checks for it explicitly.

Middleware is registered for you, on both `nova.middleware` and
`nova.api_middleware`. Finally, confirm the installation:

```bash
php artisan nova-two-factor:doctor
```

> [!NOTE]
> You should run `doctor` in your deploy pipeline. It exits non-zero on a
> misconfiguration, and it checks the things that otherwise fail silently.

> [!TIP]
> **Installing with an AI assistant?** Point it at [AGENTS.md](AGENTS.md), which
> carries the keys, the pages, and the constraints that keep an agent from
> "fixing" a failing test by switching the protection off.

## Configuration

All configuration lives in `config/nova-two-factor.php`. The options that
typically differ between environments read from your `.env` file first, so
`vendor:publish --force` can pick up new keys without flattening how a given
environment is tuned:

```dotenv
NOVA_TWO_FACTOR_ENABLED=true
NOVA_TWO_FACTOR_MODE=required            # optional | encouraged | required
NOVA_TWO_FACTOR_GRACE_DAYS=7
NOVA_TWO_FACTOR_REMIND_DAYS=7
NOVA_TWO_FACTOR_ENFORCED_FROM=2026-10-01
NOVA_TWO_FACTOR_GATE=                    # who is targeted
NOVA_TWO_FACTOR_ADMIN_GATE=              # who may administer

NOVA_TWO_FACTOR_TOTP_ENABLED=true
NOVA_TWO_FACTOR_WEBAUTHN_ENABLED=true
NOVA_TWO_FACTOR_EMAIL_ENABLED=true

# Must match the host users browse, or be a registrable parent of it.
NOVA_TWO_FACTOR_WEBAUTHN_RP_ID=admin.example.com
NOVA_TWO_FACTOR_WEBAUTHN_RP_NAME="Example Admin"
NOVA_TWO_FACTOR_WEBAUTHN_ORIGINS=https://admin.example.com

NOVA_TWO_FACTOR_EMAIL_TTL=300
NOVA_TWO_FACTOR_EMAIL_RESEND_AFTER=60

NOVA_TWO_FACTOR_STEP_UP_TTL=300
NOVA_TWO_FACTOR_PASSWORD_CONFIRMATION_TTL=900
NOVA_TWO_FACTOR_TRUSTED_DEVICES=true
NOVA_TWO_FACTOR_TRUSTED_DEVICE_DAYS=30
NOVA_TWO_FACTOR_SHOW_TRADEOFFS=true

NOVA_TWO_FACTOR_SETTINGS=false           # policy editable from the panel
NOVA_TWO_FACTOR_LOCKOUT_ALERT=0          # 0 disables the burst alert

NOVA_TWO_FACTOR_LIMIT_CHALLENGE=5
NOVA_TWO_FACTOR_LIMIT_STEP_UP=5
NOVA_TWO_FACTOR_LIMIT_RECOVERY=10
NOVA_TWO_FACTOR_LIMIT_ENROLL=10
NOVA_TWO_FACTOR_LIMIT_OTP_SEND=3
NOVA_TWO_FACTOR_LIMIT_WEBAUTHN=30
```

### Factors

| Key | Default | What it does |
|---|---|---|
| `methods.totp.enabled` | `true` | Offer authenticator applications. |
| `methods.totp.digits` / `.period` | `6` / `30` | Code length and rotation. Changing either invalidates every enrolled application, so treat them as install-time settings. |
| `methods.totp.window` | `1` | Steps of clock skew accepted either side. `1` is ±30 seconds, and raising it widens the guessing window proportionally. |
| `methods.totp.secret_bytes` | `32` | Entropy of the shared secret. |
| `methods.totp.enrollment_ttl` | `900` | Seconds an unconfirmed secret stays valid. |
| `methods.webauthn.enabled` | `true` | Offer passkeys. Requires `https`. |
| `methods.webauthn.relying_party.id` | app host | The domain credentials bind to. It must equal the host users browse, or be a registrable parent of it; a mismatch makes every passkey fail. |
| `methods.webauthn.origins` | app URL | Exact origins accepted, compared scheme-host-port with no prefix matching. |
| `methods.webauthn.user_verification` | `preferred` | Whether the authenticator must verify the human. Always forced to `required` for step-up. |
| `methods.webauthn.resident_key` | `preferred` | Ask for a discoverable credential, which lets a passkey sign in without a username. |
| `methods.webauthn.allowed_aaguids` | `[]` | Allow-list of authenticator models. Empty means any. |
| `methods.webauthn.on_counter_regression` | `reject` | What to do when a signature counter goes backwards, which may indicate a cloned key. `log` accepts and records instead. |
| `methods.email.enabled` | `true` | Offer email codes. |
| `methods.email.ttl` | `300` | Seconds a code lives. |
| `methods.email.max_attempts` | `5` | Wrong guesses before the challenge is burned. |
| `methods.email.resend_after` | `60` | Seconds before another code may be requested. |
| `methods.email.queue` | `false` | Queue the mail. Disabled by default, since a queue that is not running turns a login into a dead end. |

### Recovery Codes

| Key | Default | What it does |
|---|---|---|
| `recovery_codes.count` | `8` | How many are issued. |
| `recovery_codes.length` | `10` | Characters per code. |
| `recovery_codes.warn_at` | `3` | Remaining count at which the interface offers regeneration. |

### Enforcement

| Key | Default | What it does |
|---|---|---|
| `enforcement.mode` | `optional` | `optional`, `encouraged` or `required`. Only `required` blocks. |
| `enforcement.grace_enabled` | `true` | Whether `required` grants any runway before it blocks. |
| `enforcement.grace_mode` | `days` | `days` for a runway per account; `date` for one deadline everybody shares. |
| `enforcement.grace_days` | `7` | With `grace_mode: days`, the runway each account gets from its `created_at`. |
| `enforcement.enforced_from` | `null` | With `grace_mode: date`, the deadline for everyone. |
| `enforcement.remind_every_days` | `7` | Days that "don't remind me again" lasts under `encouraged`. The preference is stored against the account rather than the browser. |
| `enforcement.queue_reminders` | `true` | Queue the reminder mail an administrator sends. |
| `enforcement.remind_cooldown_hours` | `24` | Hours before the same recipient may be sent another reminder. The bulk action skips and reports rather than failing the batch. |
| `enforcement.gate` | `null` | Gate ability deciding who is targeted. `null` targets everyone. |
| `enforcement.except` | `[]` | Extra request patterns that stay reachable for a non-compliant user. |

### Step-Up

| Key | Default | What it does |
|---|---|---|
| `step_up.ttl` | `300` | Seconds a grant stays fresh. |
| `step_up.protect` | see config | Scopes requiring a fresh factor, matched against Nova actions and routes. |

### Trusted Devices

| Key | Default | What it does |
|---|---|---|
| `trusted_devices.enabled` | `true` | Offer "don't ask again on this device". |
| `trusted_devices.days` | `30` | How long that lasts. Never offered when a recovery code was used. |
| `trusted_devices.cookie` | `nova_two_factor_device` | Cookie name. |

### Rate Limits

Each limiter is keyed on both the user and the IP address, and whichever trips
first wins. Rejections carry a `Retry-After` header, which the interface
countdown reads.

| Key | Per user | Per IP | Window | Lockout |
|---|---|---|---|---|
| `rate_limits.challenge` | 5 | 60 | 60s | 60s, escalating to 900s |
| `rate_limits.step_up` | 5 | 60 | 60s | 60s, escalating to 900s |
| `rate_limits.recovery` | 10, or 3 malformed | 10 | 1 hour | 1 hour, flat |
| `rate_limits.enroll` | 10 | 30 | 10 min | 10 min, escalating to 1 hour |
| `rate_limits.otp_send` | 3 | 10 | 15 min | 15 min, escalating to 1 hour |
| `rate_limits.webauthn_per_minute` | 30 | — | 60s | — |

Two further options control how the challenge bucket is keyed:
`rate_limits.per_device` (default `true`) gives a browser that has cleared a
challenge before its own budget, and `rate_limits.device_cookie` names the
cookie carrying that marker. The reasoning behind the recovery allowance and the
per-browser split is in [DESIGN.md](DESIGN.md#rate-limiting).

### Other Options

| Key | Default | What it does |
|---|---|---|
| `enabled` | `true` | Turns the whole package off — routes, middleware, card and all. A codebase serving several panels may use this per domain. |
| `password_confirmation_ttl` | `900` | Seconds a password confirmation stays fresh for destructive operations. |
| `alerts.lockout_burst.accounts` | `0` | Distinct accounts locked out inside the window before `LockoutBurstDetected` fires. `0` disables it. |
| `alerts.lockout_burst.window_minutes` | `15` | The window that count is measured over. |
| `audit.enabled` | `true` | Write the audit trail. |
| `audit.queue` / `.prune_after_days` | `false` / `365` | Queue audit writes; retention for `nova-two-factor:prune`. |
| `routes.prefix` | `two-factor` | Path segment under Nova's own path. |
| `fortify.supersede_challenge` | `true` | Take over Nova's login-pipeline divert. |
| `settings.editable` | `false` | Let administrators change policy from the panel. |
| `settings.pause_max_minutes` | `120` | Longest pause the panel will offer. |
| `nova.compliance.enabled` | `true` | Register the compliance dashboard. |
| `nova.compliance.models` | `[]` | Populations compliance is measured against. Empty means the model behind Nova's guard. |
| `nova.menu.show` | `true` | Add the menu group automatically. |
| `nova.menu.label` / `.icon` | `Two-factor` / `lock-closed` | How that group reads. |
| `nova.menu.badge` | `true` | Show the overdue count on the group. |
| `nova.admin_gate` | `nova-two-factor:admin` | Gate guarding the administration pages and the reset action. Undefined abilities deny, so these start closed. `null` opens them to any Nova user. |
| `nova.replace_nova_card` | `true` | Substitute this package's card for Nova's own. |
| `ui.show_method_tradeoffs` | `true` | Show each factor's trade-off where a user picks one. |
| `database.connection` / `.tables.*` | `null` / defaults | Where the tables live, for applications keeping auth data on their own connection. |

## Managing Two-Factor Authentication

Users manage their own methods on Nova's existing **User Security** page. This
package replaces Nova's two-factor card in place, so there is nothing extra to
link to and it inherits Nova's user-menu entry.

The challenge, step-up and enrollment screens are server-rendered Blade pages
rather than Inertia pages, and work with JavaScript disabled. The reason is in
[DESIGN.md](DESIGN.md#rendering).

Wherever a user picks a factor, each option carries a one-line description and,
on its own line, its trade-off:

| | |
|---|---|
| Passkey | **Cannot be phished.** |
| Authenticator app | **Works offline.** |
| Email code | **Weakest option — anyone with your inbox has your second factor.** |

These trade-offs are shown by default. If you would rather not put them in front
of your users, you may disable them:

```php
// config/nova-two-factor.php
'ui' => [
    'show_method_tradeoffs' => false,
],
```

The descriptions remain; only the trade-off line disappears. Both come from
`MethodType`, so they are translatable and identical across every screen.

### Translations

Every user-facing string passes through `__()`, including the strings the
pre-authentication JavaScript writes into the page after load. English and
Italian ship with the package. To translate into another language, copy
`resources/lang/en.json` to `lang/vendor/nova-two-factor/{locale}.json` in your
application and translate the values.

## Authentication Methods

### Authenticator Applications

Enabled by default. The QR code is generated locally as an inline SVG and the
setup key is always offered as text, so enrollment works on a machine with no
camera and with a screen reader.

### Passkeys

Passkeys require `https` and a relying-party ID matching your host:

```php
// config/nova-two-factor.php
'methods' => [
    'webauthn' => [
        'enabled' => true,
        'relying_party' => ['id' => null], // defaults to the host of app.url
        'origins' => [],                   // defaults to [app.url]
    ],
],
```

The relying-party ID is derived from `config('app.url')`, never from the request
`Host` header, and is validated at boot as a registrable parent of your
application host. Install `ext-sodium` if you want to accept Ed25519
authenticators.

### Email Codes

Email codes require a working mailer and are labelled in the interface as the
weakest option, since anyone with the user's inbox has their second factor.

Adding an email method sends a code to the address and only counts it once that
code comes back, so an attacker holding the password cannot point a second
factor at their own inbox.

SMS is not included. The `OtpTransport` contract is the documented extension
point.

### Recovery Codes

Recovery codes are generated on first enrollment, shown once, and stored hashed
individually. The package cannot show them again, and says so where they are
displayed; the user may copy, download or print them at that moment.

The security card tracks them as a row of ticks, struck through as they are
spent, and offers regeneration at `recovery_codes.warn_at` remaining. Using a
code signs the user in; it does not turn two-factor authentication off.

### Links In Mail

Mail sent by this package links to Nova's user security page, and that link is
not built from `APP_URL`. Where Nova has a domain of its own, `APP_URL` names
the customer application instead of the panel.

Links resolve to the host the mail was sent from, then `nova.domain`, then
`APP_URL`. You should set `nova.domain` if you send reminders from a job with no
request behind it.

> [!TIP]
> The same assumption affects passkeys. `nova-two-factor:doctor` prints the
> relying party it derived; if that is your customer domain rather than the
> panel's, set `NOVA_TWO_FACTOR_WEBAUTHN_RP_ID` and
> `NOVA_TWO_FACTOR_WEBAUTHN_ORIGINS`.

## Enforcement

```php
NovaTwoFactor::make()->enforce('required', graceDays: 14);
```

| Mode | Behaviour |
|---|---|
| `optional` | Nothing required, nothing shown. |
| `encouraged` | A dismissible prompt. Never blocks a request. |
| `required` | Nova is unreachable until enrolled, once grace expires. |

Under `required`, grace has two shapes. Set `grace_enabled` to `false` and the
wall appears at the next request; otherwise `grace_mode` decides the deadline:

| `grace_mode` | Deadline |
|---|---|
| `days` | Each account's `created_at` plus `grace_days` |
| `date` | `enforced_from`, for everyone |

During the grace window, users are shown the enrollment page with a countdown to
their deadline. Choosing **Set up later** dismisses it for the remainder of the
session. Under `encouraged` users may also silence the prompt for
`enforcement.remind_every_days` days; under `required` they may not.

### Targeting Specific Users

You may restrict enforcement to a subset of users with a closure:

```php
NovaTwoFactor::make()->requireFor(fn ($user) => $user->hasRole('admin'));
```

Or by naming a gate, which keeps role logic in the authorization layer you
already use:

```php
'enforcement' => ['gate' => 'require-two-factor'],
```

> [!WARNING]
> `enforcement.except` is the most dangerous setting in this package. Patterns
> are matched with `Str::is`, so `*` crosses slashes: `['nova-api/*']` leaves
> every resource, action and metric endpoint open while the pages still
> redirect. Nothing errors and nothing is logged. `nova-two-factor:doctor`
> prints the effective list and fails on any pattern that reaches the dashboard,
> a resource page or the Nova API.

## Step-Up Authentication

You may demand a fresh factor in front of a single dangerous action:

```php
NovaTwoFactor::make()->protectWithStepUp('users.destroy', [
    'DELETE nova-api/users/*',
]);
```

Or per route:

```php
Route::delete('/danger', DangerController::class)
    ->middleware('nova.2fa.step-up:danger');
```

A grant is HMAC-signed over the session, user, scope and expiry, so proving
yourself for one scope never unlocks another. XHR requests receive a **423**
response with `step_up_required`, mirroring Laravel's own `RequirePassword`, and
the original request is replayed once the grant is issued. Passkey users have
user verification forced to `required` here, whatever the configuration says.

## Administration

The package registers three pages, each answering one question:

| Page | URL | Answers |
|---|---|---|
| **Overview** | `/dashboards/two-factor-compliance` | Are we covered, and who do I chase? |
| **Settings** | `/dashboards/two-factor-settings` | What are the rules, and can I change them? |
| **Activity** | `/resources/two-factor-audits` | What happened, and who did it? |

These pages start closed. `nova.admin_gate` points at `nova-two-factor:admin`,
an ability no fresh application defines, and `Gate::allows()` denies an
undefined ability. Until you say who may see them, nobody can, and the menu
entry does not render:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('nova-two-factor:admin', fn ($user) => $user->isAdmin());
```

You may point the option at an ability you already have, or set it to `null` to
open the pages to every user who can reach Nova.

### The Menu

Registering the tool adds a **Two-factor** group to Nova's menu, displaying the
number of users past their grace period as a badge. *Settings* appears only when
`settings.editable` is enabled, and *Activity* is otherwise hidden from
navigation. You may rename the group with `nova.menu.label`, change its icon
with `nova.menu.icon`, or remove it with `nova.menu.show`; the pages stay
registered and reachable by URL.

> [!IMPORTANT]
> If your application calls `Nova::mainMenu()`, none of this appears. A custom
> main menu replaces Nova's default one entirely, and tool menus are only
> collected for the default, so the group is dropped with no error. Place it
> yourself using the helpers below.

### The Overview Dashboard

The Overview dashboard renders a single card, read from top to bottom:

- An enrollment ring and a coverage strip.
- The method mix and failed attempts over the last thirty days.
- Six resilience figures: single-factor accounts, recovery-code health, time to
  enrol, stale enrollments, trusted devices in force, and lockouts.
- The phishing-resistant trend and recent recovery-code sign-ins.
- The queue of users to chase, overdue first.
- Recent administrator actions: reminders, resets and exemptions.

Each figure carries a **?** describing what it answers, and clicking a
resilience tile filters the queue to the accounts it counts. Each row's `…` menu
opens the user's Nova resource, sends a reminder, or resets their two-factor —
the last requiring their email address typed out, a written reason, and your
password.

The sections rendered depend on your enforcement mode: figures that cannot be
true in the current mode are neither sent nor computed. Each mode adds one
figure of its own — *blocked at the door* under `required`, *reminders sent*
under `encouraged`, and *new enrollments* under `optional`.

You may disable the dashboard with `nova.compliance.enabled`, and the menu badge
alone with `nova.menu.badge`.

### Choosing Which Users Are Measured

Every figure is measured against the model behind Nova's guard. On a panel only
administrators can reach, that counts people who can never sign in to Nova. You
may name the populations instead:

```php
'nova' => [
    'compliance' => ['models' => [App\Models\Admin::class]],
],
```

Class names only, since `config:cache` cannot serialise a closure. To narrow a
population, register it on the tool:

```php
NovaTwoFactor::make()
    ->audit(Admin::class)
    ->audit(User::class, fn ($query) => $query->where('active', true));
```

Both sources are merged, and a runtime registration wins over a configured one
for the same class.

### The Settings Page

The Settings page is disabled by default. A fresh installation should not hand
everyone who reaches Nova the ability to weaken two-factor policy:

```
NOVA_TWO_FACTOR_SETTINGS=true
```

With it enabled, an administrator may change the enforcement mode and grace, the
methods that may be enrolled, the email-code lifetime, trusted devices, the
step-up window, and two interface options. Every write is password-confirmed and
audited with both values, and the page shows the last five changes.

The form reshapes as you answer it: settings that do nothing in the chosen mode
are not shown, and settings that depend on another appear with it. Switching to
`required` displays what it would cost before you save — "would block 13 of 14
administrators" — and at least one method must remain enabled, which is refused
in the interface and again in the endpoint.

Panel values take precedence over `.env`, which takes precedence over the
shipped defaults. Where the panel and your environment disagree, the field is
marked, and **Restore defaults** clears every stored value at once.

Some options are deliberately not editable from the panel, among them `enabled`,
`nova.admin_gate`, `webauthn.relying_party.*`, `database.*` and the rate limits.
The allow-list lives in `Settings\SettingSchema`, and the reasons are in
[DESIGN.md](DESIGN.md#the-settings-page).

### Pausing Enforcement

The Settings page can pause enforcement: nobody is challenged, while the
administration pages keep working. This is the intended way to fix a
misconfigured factor, since a broken second factor is exactly when an
administrator cannot reach the page that fixes it.

Every pause carries a duration, capped by `settings.pause_max_minutes`, and
expires on its own. It is stored in the database rather than the cache, so a
cache flush cannot silently re-arm the gate, and it records who paused it and
why. Pausing does not clear anyone's verified session.

### The Activity Log

Every audited event is available as a read-only Nova resource at
`/resources/two-factor-audits`, reached from the two pages above. It is
read-only in all four directions: an audit trail an administrator can edit is
not an audit trail.

The log is filtered to administrator actions by default. *Worth a second look*
narrows it to suspicious events, and *Everything* removes the filter. Each row
reads as a sentence — "Reminder sent to Priya Raman by Alex Morgan" — and the
badge is coloured by severity.

### Placing The Menu Entry Yourself

Switch the automatic entry off and place it wherever you like. The pages stay
registered either way:

```php
'nova' => ['menu' => ['show' => false]],
```

```php
use Gabrielesbaiz\NovaTwoFactor\NovaTwoFactor;

Nova::mainMenu(fn ($request) => [
    MenuSection::dashboard(Main::class)->icon('chart-bar'),

    MenuGroup::make(__('Two-factor'), NovaTwoFactor::menuItems()),
    MenuItem::resource(User::class),

    NovaTwoFactor::menuSection(),   // or the whole group as a top-level entry
]);
```

`menuItems()` returns Overview, Settings (when editing is enabled) and Activity;
`menuItem()` returns the first alone. Every helper carries its destination's own
authorization, so placing an entry by hand cannot expose it to someone the gate
would have refused. `NovaTwoFactor::make()->withoutMenu()` is the fluent
equivalent of the configuration option.

### Resources, Fields and Actions

```php
use Gabrielesbaiz\NovaTwoFactor\Nova\Actions\ResetTwoFactorAuthentication;
use Gabrielesbaiz\NovaTwoFactor\Nova\Actions\RevokeTrustedDevices;
use Gabrielesbaiz\NovaTwoFactor\Nova\Actions\SendEnrollmentReminder;
use Gabrielesbaiz\NovaTwoFactor\Nova\Fields\TwoFactorStatus;
use Gabrielesbaiz\NovaTwoFactor\Nova\Metrics\TwoFactorAdoption;
use Gabrielesbaiz\NovaTwoFactor\Nova\Metrics\TwoFactorFailures;
use Gabrielesbaiz\NovaTwoFactor\Nova\Metrics\TwoFactorMethodMix;

public function fields(NovaRequest $request): array
{
    return [
        // Sortable via subquery, so it does not N+1 a large index.
        TwoFactorStatus::make(),
    ];
}

public function actions(NovaRequest $request): array
{
    return [
        ResetTwoFactorAuthentication::make(), // requires a written reason
        SendEnrollmentReminder::make(),       // mails whoever has not enrolled
        RevokeTrustedDevices::make(),
    ];
}

public function cards(NovaRequest $request): array
{
    return [
        new TwoFactorAdoption(User::class),
        new TwoFactorMethodMix,
        new TwoFactorFailures,
    ];
}
```

`SendEnrollmentReminder` mails the users who have not enrolled, skipping anyone
who has. The mail carries their own grace deadline under `required`, quotes an
optional note as coming from the administrator who sent it, and every send is
audited.

## Security

| Control | Implementation |
|---|---|
| TOTP secrets | `encrypted` cast, 160-bit, never in a URL or log |
| Recovery codes | SHA-256, individually, unique index, single-use |
| Email codes | HMAC-SHA256 keyed on `APP_KEY`, single-use, short TTL |
| Passkey credentials | `encrypted:json`; lookup by SHA-256 of the credential ID |
| Trusted devices | 64-character token, only its hash stored |
| Step-up grants | HMAC over session, user, scope and expiry |
| QR codes | Generated locally. There is no remote code path. |

Routes that issue or destroy a factor require a **verified session** — a cleared
challenge, not merely a confirmed password. The only exception is an account
with nothing enrolled yet, which has no second factor to prove.

Removing a method, revealing or regenerating recovery codes, and revoking
devices each require a fresh password confirmation, applied as route middleware.
Removing the last factor is refused while enforcement requires one.

Every enrollment, challenge, lockout, replay, reset and device change is
recorded. Codes, secrets, credentials and destinations never are; a guard throws
outside production if a payload even looks like it carries one.

The cryptographic choices, the rate-limiting design and the threat model behind
them are documented in [DESIGN.md](DESIGN.md).

## Extending

You may register your own factor type:

```php
use Gabrielesbaiz\NovaTwoFactor\Facades\TwoFactor;

TwoFactor::extend('yubico-otp', fn ($app) => new YubicoDriver(...));
```

Implement `Contracts\TwoFactorMethodDriver`. Enrollment must be idempotent for
the lifetime of a pending enrollment, or refreshing the setup page will
invalidate the QR code the user has just scanned.

Sending email codes over another transport, listening to two-factor events, and
alerting on a lockout burst are covered in
[DESIGN.md](DESIGN.md#extending-the-package).

## Troubleshooting

<details>
<summary><strong>"Everyone is locked out at once"</strong></summary>

Several lockouts at once usually means somebody is working through a list of
stolen passwords. They cannot get in; under `required` they can keep others out.

- **Trusted devices keep working.** A browser that has already cleared a
  challenge is unaffected.
- **Lockouts expire** after `rate_limits.challenge.lockout` seconds, rising to
  `lockout_ceiling`. Nobody has to clear anything.
- **An administrator may free one account now** by resetting its second factor
  from the Overview dashboard.
- **`php artisan nova-two-factor:reset user@example.com`** does the same from
  the command line, and also clears that user's rate-limit buckets.

To hear about it rather than discover it, set a threshold and listen for
`LockoutBurstDetected`:

```php
'alerts' => ['lockout_burst' => ['accounts' => 5, 'window_minutes' => 15]],
```

</details>

<details>
<summary><strong>"Codes are always invalid"</strong></summary>

Clock skew, nearly always. TOTP codes are derived from the current time, so a
phone whose clock has drifted generates codes the server rejects. Set the device
clock to automatic. You may widen the window if you must — each step is 30
seconds either way:

```php
'methods' => ['totp' => ['window' => 2]],
```

</details>

<details>
<summary><strong>"Passkeys aren't offered"</strong></summary>

Run `php artisan nova-two-factor:doctor`. Almost always one of:

- `app.url` is not `https` (`localhost` is exempt)
- the relying-party ID is not a registrable parent of the host you are browsing
- the browser has no platform authenticator

</details>

<details>
<summary><strong>"I'm locked out of my own admin panel"</strong></summary>

```bash
php artisan nova-two-factor:reset admin@example.com
```

This clears every method, recovery code and trusted device, every rate-limit
bucket keyed to that user, every session predating the reset, and any reminder
snooze. An audit row is written and attributed. There is deliberately no way to
do this from inside the panel you cannot reach.

```bash
# No prompt, for scripts and support tooling.
php artisan nova-two-factor:reset admin@example.com --force

# Clear an address the audit trail does not know about.
php artisan nova-two-factor:reset admin@example.com --ip=203.0.113.7
```

</details>

<details>
<summary><strong>"The UI looks unstyled after upgrading"</strong></summary>

Republish the assets:

```bash
php artisan vendor:publish --tag=nova-two-factor-assets --force
php artisan vendor:publish --tag=nova-assets --force
```

</details>

## Artisan Commands

| Command | Purpose |
|---|---|
| `nova-two-factor:doctor` | Check the configuration. Non-zero exit on failure. |
| `nova-two-factor:reset {user}` | Break-glass reset: methods, codes, devices, lockouts, sessions and reminder snooze. Accepts `--force` and `--ip=`. |
| `nova-two-factor:prune` | Remove expired challenges, devices and old audit rows. |
| `nova-two-factor:upgrade` | Port 1.x `nova_twofa` data. See [UPGRADE.md](UPGRADE.md). |

You should schedule the prune:

```php
Schedule::command('nova-two-factor:prune')->daily();
```

## Testing

```bash
composer test        # Pest
composer analyse     # PHPStan
composer format      # Pint
```

The suite includes a regression test for every vulnerability found in 1.x.

## Contributing

Thank you for considering contributing. The guide is in
[CONTRIBUTING.md](CONTRIBUTING.md).

## Security Vulnerabilities

Please review [SECURITY.md](SECURITY.md) for reporting a vulnerability. Please
do not open a public issue.

## Credits

Written and maintained by [Gabriele Sbaiz](https://github.com/gabrielesbaiz).

This package builds on Laravel, Nova, Fortify,
[web-auth/webauthn-lib](https://github.com/web-auth/webauthn-framework),
[pragmarx/google2fa](https://github.com/antonioribeiro/google2fa),
[bacon/bacon-qr-code](https://github.com/Bacon/BaconQrCode),
[spatie/laravel-package-tools](https://github.com/spatie/laravel-package-tools),
and the WebAuthn and TOTP specifications.

## Support This Package

I maintain this on evenings and weekends, alongside a full-time job writing
insurance software. Keeping it green across new Laravel and Nova majors is the
unglamorous part, and it is what keeps this installable in your
`composer.json` next year too.

If it is useful to you:

- ⭐ **Star the repo.** Free, thirty seconds, and it is the first signal other developers look at.
- ❤️ **[Become a sponsor](https://github.com/sponsors/gabrielesbaiz).** From $5 a month. Company tiers get your logo right here in this README.
- 🐛 **Open a good issue.** A clear reproduction is worth more than you think.
- 🗣️ **Tell another Laravel developer.** Word of mouth is how packages survive.

[![Sponsor on GitHub](https://img.shields.io/badge/Sponsor-gabrielesbaiz-ff69b4?style=for-the-badge&logo=github-sponsors)](https://github.com/sponsors/gabrielesbaiz)

## Disclaimer

This package is provided **as is**, without warranty of any kind, express or
implied, including but not limited to the warranties of merchantability,
fitness for a particular purpose, title and non-infringement. To the fullest
extent permitted by applicable law, in no event shall the authors, copyright
holders or contributors be liable for any claim, damages or other liability —
whether in an action of contract, tort or otherwise — arising from, out of or in
connection with this package or its use, including without limitation any
direct, indirect, incidental, special, exemplary, consequential or punitive
damages, loss of data, loss of profits, business interruption, account
lockouts, unauthorised access, or failure of any authentication control.

Two-factor authentication is a security control: whoever deploys it is
responsible for it. That responsibility includes, and is not limited to,
choosing appropriate configuration, running `nova-two-factor:doctor` before
relying on it, testing enforcement and recovery on your own infrastructure,
keeping recovery paths available to your users, meeting whatever regulatory or
contractual obligations apply to you, and reviewing the code yourself before
putting it in front of an account you cannot afford to lose. Nothing here
constitutes security, legal or compliance advice, and no claim is made that this
package makes any system, application or organisation secure or compliant with
any standard.

Use of this package is entirely at your own risk.

## License

MIT. See [LICENSE.md](LICENSE.md). The MIT licence's warranty disclaimer and
limitation of liability apply in full, alongside the section above.
