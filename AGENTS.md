# Notes for AI agents

You are installing or configuring **a security control**. The failure mode that
matters here is not a broken build — it is a working build with the protection
quietly turned off, which nothing in CI will catch and nobody will notice until
it is needed.

Read the rules before the reference.

## Rules

**Never weaken enforcement to make something else work.** Specifically, do not:

- set `NOVA_TWO_FACTOR_ENABLED=false`
- lower `enforcement.mode` (`required` → `encouraged` → `optional`)
- disable a method (`methods.*.enabled`)
- widen `trusted_devices.days` or `step_up.ttl`

…in order to fix a failing test, an unrelated bug, a local environment without
HTTPS, or a complaint about inconvenience. **"Make signing in easier" is not
authorisation to remove a second factor.** If a task appears to require one of
these, stop and ask, naming which protection would be reduced.

**`enabled=false` is the dangerous one.** It is a legitimate setting — a
multi-domain install turns the package off per domain — so `doctor` reports it
as valid, no test fails, and no badge turns red. An application with it set is
indistinguishable from one without the package, and nothing will tell you.

**Never write settings through `tinker`, a seeder, or the database.** The
settings table outranks `.env`, so a value written there survives every
redeploy. A wrong `.env` value is corrected by the next deploy; a wrong overlay
value is permanent until a human clicks *Restore defaults* in the panel. Use
`.env` or the Settings page.

**Run `php artisan nova-two-factor:doctor` after any change**, and report every
line that is not `PASS`, verbatim. Do not summarise it as "fine". It checks the
things that fail silently: unregistered middleware, stale published assets, a
relying party derived from the wrong domain.

**Before changing `enforcement.mode` to `required`, state the blast radius.**
`GET {nova}/two-factor/compliance` returns `impact.without_factor` — how many
users would be locked out of Nova. On most panels the honest answer is "most of
them", and the correct next step is sending reminders, not flipping the switch.

**To unblock local work, pause instead of disabling.** The Settings page has a
pause: it stands enforcement down for everyone, keeps the admin pages reachable,
expires by itself, and is audited. That is the intended escape hatch. A local
`.env` value is acceptable if it is local; never commit one.

## Install

```bash
composer require gabrielesbaiz/nova-two-factor
php artisan vendor:publish --tag=nova-two-factor-migrations
php artisan migrate
php artisan vendor:publish --tag=nova-two-factor-config
php artisan vendor:publish --tag=nova-two-factor-assets   # pre-auth pages need this
php artisan nova-two-factor:doctor
```

Then, all three required:

1. `use Gabrielesbaiz\NovaTwoFactor\Concerns\HasTwoFactorAuthentication;` on
   every authenticatable model that may hold a factor.
2. `NovaTwoFactor::make()` in `NovaServiceProvider::tools()`.
3. Nova's `twoFactorAuthentication` **and** `updatePasswords` Fortify features
   left enabled — the first is what makes Nova render the card this package
   replaces, the second registers `nova.password.confirm`, which guards every
   destructive route here.

## Facts that are easy to get wrong

- **Config keys are real.** Do not invent them. `config/nova-two-factor.php` is
  the list, and `README.md` documents every one. A test fails if the README
  names a key that does not exist.
- **Never hardcode `/two-factor/…`.** The segment is `routes.prefix`, and the
  package ships `Support\Routing::prefix()` and `Support\PanelUrl` for building
  URLs. The SPA reads it from card meta.
- **`APP_URL` is not the panel.** Where Nova has its own domain, `APP_URL` is
  the customer site. It decides the passkey relying party and, without
  `PanelUrl`, the links in mail. Check `doctor`'s "Passkeys" line.
- **Pre-auth screens are Blade, not Inertia**, and that is deliberate: a
  tool-registered Inertia page never resolves on a cold load. Do not convert
  them.
- **The three admin pages** are `/dashboards/two-factor-compliance`,
  `/dashboards/two-factor-settings` and `/resources/two-factor-audits`. The
  second appears only when `NOVA_TWO_FACTOR_SETTINGS=true`.
- **A custom `Nova::mainMenu()` discards tool menus entirely.** Nothing appears
  and no error says why. Place the entries with `NovaTwoFactor::menuItems()`.
- **Compliance counts the guard's model** unless
  `NOVA_TWO_FACTOR_COMPLIANCE_MODELS` names others. On an admin-only panel,
  counting every customer makes adoption look high and wrong.

## Working on this package

- `composer test` (Pest), `composer analyse` (PHPStan), `composer format`
  (Pint), `npm run build` (both bundles — the tool bundle *and* the pre-auth
  one).
- Every user-facing string goes through `__()` and must exist in
  `resources/lang/en.json`; a test enforces it. Italian is maintained beside it.
- Avoid plain keys for words a host application also translates — `Required`,
  `Settings`, `Activity`. An app's own `lang/{locale}.json` beats a package's,
  and enforcement mode names live in a namespaced catalogue for that reason.
- Notifications capture the locale at construction. They are queued, and a
  worker has no request to read a locale from.
