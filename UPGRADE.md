# Upgrading to 2.0

2.0 is a complete rewrite. Nothing is shared with 1.x: new tables, new
namespaces, new configuration, new routes.

> [!CAUTION]
> **Treat every 1.x TOTP secret as compromised.**
>
> 1.x defaulted `use_google_qr_code_api` to `true`, which sent the full
> `otpauth://` provisioning URI — shared secret included — to the third-party
> service `api.qrserver.com` inside a GET query string. Those secrets are
> therefore potentially in that provider's access logs, in your administrators'
> browser history, and in any TLS-inspecting proxy on the path.
>
> The safe upgrade is to have everybody **re-enrol**, not to migrate. The
> migration command exists for deployments where that is genuinely impractical.

## Why 2.0 exists

A security review of 1.x found, among other things:

| Severity | Issue |
|---|---|
| Critical | Every endpoint under `nova-vendor/nova-two-factor/*` was reachable **unauthenticated** — the route group had no auth middleware, and `Authorize` only checked tool visibility, which defaults to allow. |
| Critical | TOTP secrets sent to a third-party QR service by default. |
| Critical | No rate limiting anywhere. A six-digit code with unlimited attempts. |
| High | `toggle2Fa()` disabled two-factor with no password, no code, no notification and no audit trail. |
| High | Secrets stored in plaintext by default, while the README advertised "encrypted". |
| High | The challenge middleware failed open four separate ways, and substituted a view body instead of halting the request — so any non-HTML endpoint stayed usable. |
| High | Enforcement middleware was never mentioned in the install steps, so `mandatory => true` was a silent no-op; when wired up it hardcoded the `admin` path and produced a redirect loop. |
| Medium | Using the single recovery code **deleted the entire two-factor record**, making a backup code a self-service way to switch protection off. |
| Medium | No replay protection. A captured code stayed valid for its whole window. |
| Medium | No session regeneration on success, and no clearing on logout. |

Each of these now has a regression test.

## Steps

```bash
composer require gabrielesbaiz/nova-two-factor:^2.0

# The 1.x config and migration are both superseded.
rm config/nova-two-factor.php
php artisan vendor:publish --tag=nova-two-factor-config
php artisan vendor:publish --tag=nova-two-factor-migrations
php artisan migrate

# Preview, then run.
php artisan nova-two-factor:upgrade --dry-run
php artisan nova-two-factor:upgrade

php artisan nova-two-factor:doctor
```

`nova_twofa` is left in place. Once sign-in is verified:

```bash
php artisan nova-two-factor:upgrade --drop-legacy-table
```

## What the migration does

| 1.x | 2.0 |
|---|---|
| `google2fa_secret` (plaintext *or* `Crypt::encrypt`-ed, depending on a flag that could be toggled at any time) | Decrypted if needed, then written to `two_factor_methods.secret`, always encrypted |
| `confirmed = 1` | `confirmed_at` |
| `confirmed = 0` | A pending row, pruned if never confirmed |
| `recovery` | **Not migrated.** See below. |
| `google2fa_enable = 0` | Migrated as enrolled. **Behaviour change** — see below. |

### Recovery codes cannot be migrated

The 1.x code was bcrypt-hashed; the new scheme needs a SHA-256 of the plaintext,
which nobody has. **No user will have recovery codes after upgrading.** Ask
everybody to generate a set from the User Security page.

This is the right outcome regardless: 1.x issued a single code, upper-cased it —
collapsing base62 to base36 and discarding roughly 44 bits — and consuming it
deleted the user's entire two-factor configuration.

### Users who had two-factor "switched off"

In 1.x a user with a record but `google2fa_enable = 0` was waved straight past
the gate. In 2.0 that user is enrolled and **will be challenged**.

The command reports how many are affected. To skip them instead:

```bash
php artisan nova-two-factor:upgrade --disable-unconfirmed
```

Warn those users before you run it, or their next sign-in will ask for a code
they are not expecting.

## Configuration mapping

Every 1.x key is gone. `doctor` fails if a stale published config still has them.

| 1.x | 2.0 |
|---|---|
| `mandatory: true` | `enforcement.mode: 'required'` |
| `reauthorize_urls` | `step_up.protect` — now keyed by scope, and wildcard- and method-aware |
| `reauthorize_timeout` (minutes) | `step_up.ttl` (**seconds**) |
| `except_routes` | `enforcement.except` — now supports wildcards |
| `encrypt_google2fa_secrets` | Removed. Always encrypted. |
| `use_google_qr_code_api` | **Removed.** No remote QR code path exists. |
| `user_model`, `user_table`, `user_id_column` | Removed. Polymorphic, so any number of authenticatable models work. |
| `showin_sidebar`, `menu_text`, `menu_icon` | `nova.menu.*` |

## Code changes

```diff
-use Gabrielesbaiz\NovaTwoFactor\ProtectWith2FA;
+use Gabrielesbaiz\NovaTwoFactor\Concerns\HasTwoFactorAuthentication;

 class User extends Authenticatable
 {
-    use ProtectWith2FA;
+    use HasTwoFactorAuthentication;
 }
```

| 1.x | 2.0 |
|---|---|
| `hasTwoFactorAuthenticationEnable()` | `hasTwoFactorEnabled()` |
| `hasTwoFactorAuthentication()` | `twoFactorMethods()->exists()` |
| `hasNotTwoFactorAuthentication()` and the other negations | Removed — there is one predicate now. Having six mutual negations is how the 1.x middleware ended up consulting the wrong one. |
| `twoFa()` relation | `twoFactorMethods()` (now many) |
| `Http\Middleware\TwoFa` | Registered automatically. Remove it from `nova.middleware`. |
| `Http\Middleware\TwoFaMandatory` | Registered automatically. Remove it. |

**Remove the 1.x middleware from `config/nova.php`.** Those classes no longer
exist, and Nova will fail to boot with them listed.

## After upgrading

1. `php artisan nova-two-factor:doctor` — expect all passes.
2. Sign in as a migrated user and confirm their authenticator still works.
3. Tell users to generate recovery codes.
4. Seriously consider `nova-two-factor:reset` for everyone, given the secret
   exposure above.
