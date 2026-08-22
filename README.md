# Nova Two-Factor

Two-factor authentication for Laravel Nova 5: authenticator apps, passkeys, email codes and recovery codes, with enforcement policies, step-up re-authentication, trusted devices and admin oversight.

[![Latest version](https://img.shields.io/packagist/v/gabrielesbaiz/nova-two-factor.svg?style=flat-square)](https://packagist.org/packages/gabrielesbaiz/nova-two-factor)
[![PHP](https://img.shields.io/packagist/dependency-v/gabrielesbaiz/nova-two-factor/php?style=flat-square)](composer.json)
[![Downloads](https://img.shields.io/packagist/dt/gabrielesbaiz/nova-two-factor.svg?style=flat-square)](https://packagist.org/packages/gabrielesbaiz/nova-two-factor)
[![License](https://img.shields.io/packagist/l/gabrielesbaiz/nova-two-factor.svg?style=flat-square)](LICENSE.md)

---

> [!IMPORTANT]
> **Upgrading from 1.x?** Read [UPGRADE.md](UPGRADE.md) first. 1.x had critical
> vulnerabilities, including unauthenticated endpoints and TOTP secrets sent to a
> third-party QR service by default. **Treat every 1.x secret as compromised**
> and have users re-enrol.

## Do you need this?

Nova 5 ships two-factor authentication of its own, via Laravel Fortify. If you
want authenticator apps and recovery codes for individual users who opt in,
**you do not need this package** — turn Nova's on and stop reading:

```php
use Laravel\Fortify\Features;

Nova::fortify()->features([
    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]),
]);
```

This package exists for what that cannot do.

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
| **TOTP replay protection** | partial¹ | ✅ |
| Recovery codes hashed individually | —² | ✅ |

¹ Fortify caches a hash of the submitted code, but wraps the cache in
`optional()` — an application with no cache binding silently gets no replay
protection at all. This package persists the accepted timestep on the method
row, so it survives a cache flush, a driver swap and a multi-node deployment.

² Fortify stores recovery codes as an encrypted JSON array of *plaintext* codes.
They are reversible with the application key. Here each code is hashed
individually behind a unique index.

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- Nova 5.7+
- `https` in production (passkeys need a secure context; `localhost` is exempt)

## Installation

```bash
composer require gabrielesbaiz/nova-two-factor
php artisan vendor:publish --tag=nova-two-factor-migrations
php artisan migrate
php artisan vendor:publish --tag=nova-two-factor-config

# The challenge, step-up and enrollment pages render outside Nova's shell, so
# their script has to live under public/.
php artisan vendor:publish --tag=nova-two-factor-assets
```

Add the trait to every authenticatable model that should be able to hold a
second factor:

```php
use Gabrielesbaiz\NovaTwoFactor\Concerns\HasTwoFactorAuthentication;

class User extends Authenticatable
{
    use HasTwoFactorAuthentication;
}
```

Register the tool in `App\Providers\NovaServiceProvider`:

```php
use Gabrielesbaiz\NovaTwoFactor\NovaTwoFactor;

public function tools(): array
{
    return [
        NovaTwoFactor::make(),
    ];
}
```

Nova's two-factor *feature flag* must stay enabled, for a narrower reason than
it looks: Nova's `UserSecurity.vue` only renders the two-factor card when the
feature is on, and the card is what this package replaces. `updatePasswords` is
what registers `nova.password.confirm`, which guards every destructive route
here.

```php
use Laravel\Fortify\Features;

Nova::fortify()->features([
    Features::updatePasswords(),
    // 'confirm' => false: enrollment confirmation is handled by this package,
    // not by Fortify's own endpoint.
    Features::twoFactorAuthentication(['confirm' => false, 'confirmPassword' => false]),
]);
```

> [!WARNING]
> With the feature enabled, Fortify's `RedirectIfTwoFactorAuthenticatable` stays
> in the login pipeline and will divert any user who already has a
> `two_factor_secret` on their row to Nova's own challenge — before this
> package's middleware ever runs. If your application previously used Fortify's
> or Nova's two-factor, or ships its own on top of the
> `TwoFactorAuthenticatable` trait, clear `two_factor_secret` /
> `two_factor_recovery_codes` as part of the migration, or the two systems will
> both try to challenge the same login.

Then confirm the install:

```bash
php artisan nova-two-factor:doctor
```

> [!NOTE]
> Run `doctor` in your deploy pipeline. It exits non-zero on a misconfiguration,
> and it checks specifically for the things that otherwise fail *silently* —
> notably enforcement enabled with its middleware missing, which is how 1.x
> installs ended up with `mandatory => true` doing nothing at all.

**Middleware is registered for you**, on both `nova.middleware` and
`nova.api_middleware`. Guarding only the page routes leaves every `nova-api/*`
endpoint reachable, which was a real 1.x bypass; `doctor` verifies both.

## Where the UI lives

Users manage their own methods on Nova's existing **User Security** page — this
package replaces Nova's two-factor card in place. There is nothing extra to link
to, and it inherits Nova's user-menu entry.

The challenge, step-up and mandatory-enrollment screens are server-rendered and
work with JavaScript disabled.

<details>
<summary><strong>Why those screens are Blade rather than Inertia</strong></summary>

Nova resolves the initial Inertia component inside `Nova.countdown()`, which runs
*before* any tool script executes. `Nova.inertia()` therefore always registers
too late for a cold page load, and a tool-registered page renders
`pages/Loading.vue` forever. A two-factor challenge is always a cold load, so it
cannot be an Inertia page. Being Blade also means the login path survives a
JavaScript failure, which for a login screen is a requirement rather than a
nicety.
</details>

## Methods

### Authenticator apps (TOTP)

On by default. The QR code is generated locally as an inline SVG and the setup
key is always offered as text, so the flow works on a machine with no camera and
with a screen reader.

### Passkeys

Needs `https` and a relying-party ID that matches your host.

```php
// config/nova-two-factor.php
'webauthn' => [
    'enabled' => true,
    'relying_party' => ['id' => null], // defaults to the host of app.url
    'origins' => [],                   // defaults to [app.url]
],
```

The RP ID is derived from `config('app.url')`, **never** from the request `Host`
header, and is validated at boot as a registrable parent of your app host.

Install `ext-sodium` if you want to accept Ed25519 authenticators.

### Email codes

Needs a working mailer. Ships enabled, and labelled honestly in the UI as the
weakest option — anyone with the user's inbox has their second factor.

Adding an email method sends a code to the address and only counts it once that
code comes back. Without that, "add email 2FA pointing at attacker@example.com"
is an account-takeover primitive.

**SMS is not included.** The `OtpTransport` contract is the documented extension
point, but shipping SMS means shipping a toll-fraud surface, so it is left to
applications that actually need it.

## Enforcement

```php
NovaTwoFactor::make()->enforce('required', graceDays: 14);
```

| Mode | Behaviour |
|---|---|
| `optional` | Nothing required, nothing shown. |
| `encouraged` | A dismissible prompt. Never blocks a request. |
| `required` | Nova is unreachable until enrolled, once grace expires. |

Grace is measured from `enforcement.enforced_from` when set — "everybody must
comply by 1 October" — and otherwise from each user's `created_at`, so every new
account gets the same runway. No extra column either way.

### Only some users

```php
// Delegate to your own authorization layer.
NovaTwoFactor::make()->requireFor(fn ($user) => $user->hasRole('admin'));
```

Or name a gate in config, which keeps role logic with Spatie Permission, Bouncer
or whatever you already use:

```php
'enforcement' => ['gate' => 'require-two-factor'],
```

## Step-up re-authentication

Demand a fresh factor in front of one dangerous action:

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
yourself for one scope never unlocks another. XHR requests get **423** with
`step_up_required`, mirroring Laravel's own `RequirePassword` — one axios
interceptor handles both, and the original request is replayed on success.

Passkey users get user verification forced to `required` here regardless of
configuration, because the whole point of a step-up is that the proof is fresh.

## Admin oversight

```php
use Gabrielesbaiz\NovaTwoFactor\Nova\Actions\ResetTwoFactorAuthentication;
use Gabrielesbaiz\NovaTwoFactor\Nova\Actions\RevokeTrustedDevices;
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
        RevokeTrustedDevices::make(),
    ];
}

public function cards(NovaRequest $request): array
{
    return [
        new TwoFactorAdoption(User::class),
        new TwoFactorMethodMix,   // watch passkey share, not just the total
        new TwoFactorFailures,    // a spike here is credential stuffing
    ];
}
```

Restrict all of it with a gate:

```php
'nova' => ['admin_gate' => 'manage-two-factor'],
```

## Security

### What is protected, and how

| Control | Implementation |
|---|---|
| TOTP secrets | `encrypted` cast, 160-bit, never in a URL or log |
| Recovery codes | SHA-256, individually, unique index, single-use |
| Email codes | HMAC-SHA256 keyed on `APP_KEY`, single-use, short TTL |
| Passkey credentials | `encrypted:json`; lookup by SHA-256 of the credential ID |
| Trusted devices | 64-char token, only its hash stored |
| Step-up grants | HMAC over session + user + scope + expiry |
| QR codes | Generated locally, always. There is no remote code path. |

<details>
<summary><strong>Why recovery codes are SHA-256 and not bcrypt</strong></summary>

Deliberately the opposite of the password case. A code carries ~119 bits of
entropy, so a slow KDF buys nothing that the entropy has not already bought —
but it costs the unique index. With bcrypt you must load every row for a user and
loop `Hash::check()`, which under credential stuffing is a CPU-exhaustion vector.
SHA-256 plus a unique index makes verification one indexed equality lookup,
followed by `hash_equals()`. This is the same reasoning Laravel applies to
password reset tokens and API tokens.
</details>

<details>
<summary><strong>Why replay protection lives in the database</strong></summary>

The accepted TOTP timestep is claimed with a conditional `UPDATE`:

```sql
UPDATE two_factor_methods
   SET last_timestep = ?
 WHERE id = ? AND (last_timestep IS NULL OR last_timestep < ?)
```

The affected-row count decides who won, so it is correct under concurrent
requests and across application servers with no lock and no transaction. It also
survives a cache flush, unlike a cache-based scheme.

Note the library's own `verifyKeyNewer()` compares with `>=` and will happily
re-accept the same timestep, so it cannot be relied on for this.
</details>

### Rate limits

Every limiter is keyed on **both** the user and the IP: an IP-only limit lets a
botnet spread an attack across hosts, and a user-only limit lets one host walk
the whole user table.

| Limiter | Per user | Per IP | Window |
|---|---|---|---|
| `challenge` | 5 | 20 | 1 min, then exponential to 15 min |
| `step-up` | 5 | 20 | 1 min |
| `recovery` | 3 | 10 | 1 hour |
| `enroll` | 10 | 30 | 10 min |
| `otp-send` | 3 | 10 | 15 min |

Rejections carry `Retry-After`, and the UI countdown reads that header rather
than guessing.

### Password confirmation

Removing a method, revealing or regenerating recovery codes, and revoking devices
all require a fresh password confirmation, applied as route middleware so no
controller can forget it. Removing the **last** factor is refused outright while
enforcement requires one.

### Audit trail

Every enrollment, challenge, lockout, replay, reset and device change is
recorded. Codes, secrets, credentials and destinations never are — a guard throws
outside production if a payload even looks like it carries one.

### Reporting a vulnerability

See [SECURITY.md](SECURITY.md). Please do not open a public issue.

## Recipes

<details>
<summary>Add your own factor type</summary>

```php
use Gabrielesbaiz\NovaTwoFactor\Facades\TwoFactor;

TwoFactor::extend('yubico-otp', fn ($app) => new YubicoDriver(...));
```

Implement `Contracts\TwoFactorMethodDriver`. Enrollment must be idempotent for
the lifetime of a pending enrollment, or refreshing the setup page will
invalidate the QR the user just scanned.
</details>

<details>
<summary>Send email codes over SMS instead</summary>

Bind your own transport:

```php
$this->app->bind(OtpTransport::class, TwilioOtpTransport::class);
```
</details>

<details>
<summary>React to two-factor events</summary>

```php
use Gabrielesbaiz\NovaTwoFactor\Contracts\AuditableEvent;

Event::listen(AuditableEvent::class, function (AuditableEvent $event) {
    // Every two-factor event, through one interface.
});
```

Listen on the interface, not on the base class: Laravel's dispatcher resolves
listeners through `class_implements()`, so a listener bound to a parent *class*
never fires for its subclasses.
</details>

## Troubleshooting

<details>
<summary><strong>"Codes are always invalid"</strong></summary>

Clock skew, nearly always. TOTP codes are derived from the current time, so a
phone whose clock is set manually and has drifted will generate codes the server
rejects. Set the device clock to automatic.

Widen the window if you must — each step is 30 seconds either way:

```php
'totp' => ['window' => 2],
```
</details>

<details>
<summary><strong>"Passkeys aren't offered"</strong></summary>

Run `php artisan nova-two-factor:doctor`. Almost always one of:

- `app.url` is not `https` (browsers refuse the ceremony outside a secure
  context; `localhost` is exempt)
- the RP ID is not a registrable parent of the host you are browsing
- the browser has no platform authenticator
</details>

<details>
<summary><strong>"I'm locked out of my own admin panel"</strong></summary>

```bash
php artisan nova-two-factor:reset admin@example.com
```

Removes every method, code and trusted device for that user, and writes an audit
row. There is deliberately no way to do this from inside the panel you cannot
reach.
</details>

<details>
<summary><strong>"The UI looks unstyled after upgrading"</strong></summary>

Rebuild and republish the assets:

```bash
php artisan vendor:publish --tag=nova-assets --force
```
</details>

## Commands

| Command | Purpose |
|---|---|
| `nova-two-factor:doctor` | Check the configuration. Non-zero exit on failure. |
| `nova-two-factor:reset {user}` | Break-glass reset for a locked-out user. |
| `nova-two-factor:prune` | Remove expired challenges, devices and old audit rows. |
| `nova-two-factor:upgrade` | Port 1.x `nova_twofa` data. See [UPGRADE.md](UPGRADE.md). |

Schedule the prune:

```php
Schedule::command('nova-two-factor:prune')->daily();
```

## Testing

```bash
composer test        # Pest
composer analyse     # PHPStan
composer format      # Pint
```

The suite includes a regression test for every vulnerability found in 1.x. See
[CONTRIBUTING.md](CONTRIBUTING.md).

## Credits

Originally a fork of [visanduma/nova-two-factor](https://github.com/Visanduma/nova-two-factor).
2.0 is a complete rewrite and shares no code with it.

- [Gabriele Sbaiz](https://github.com/gabrielesbaiz)
- [All contributors](../../contributors)

## License

MIT. See [LICENSE.md](LICENSE.md).
