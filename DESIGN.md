# Design Notes

This document records why Nova Two-Factor works the way it does. The
[README](README.md) tells you what each setting does; this file tells you what
the alternative was, and why it was not chosen.

It is written for reviewers, for anyone auditing the package before deploying
it, and for contributors proposing a change to one of these behaviours. None of
it is required reading to install or operate the package.

## Contents

- [Why not Nova's built-in two-factor](#why-not-novas-built-in-two-factor)
- [The login pipeline](#the-login-pipeline)
- [Cryptography and storage](#cryptography-and-storage)
- [Rate limiting](#rate-limiting)
- [Rendering](#rendering)
- [Enforcement policy](#enforcement-policy)
- [Compliance measurement](#compliance-measurement)
- [The settings page](#the-settings-page)
- [Administrative access](#administrative-access)
- [Mail](#mail)
- [Extending the package](#extending-the-package)

## Why not Nova's built-in two-factor

Nova ships two-factor authentication through Laravel Fortify. For authenticator
apps and recovery codes, opted into by individual users, it is enough, and the
README says so. Two details of that implementation are worth knowing before
choosing between them.

**Fortify's replay protection depends on a cache binding.** It caches a hash of
the submitted code, but wraps the cache call in `optional()`. An application
with no cache binding therefore gets no replay protection at all, silently. This
package persists the accepted timestep on the method row instead, so protection
survives a cache flush, a driver swap and a multi-node deployment.

**Fortify's recovery codes are reversible.** They are stored as an encrypted
JSON array of plaintext codes, so anybody holding the application key holds the
codes. Here each code is hashed individually behind a unique index, and no code
is ever recoverable after it is shown.

## The login pipeline

### Why Fortify's two-factor feature must stay enabled

Nova's `UserSecurity.vue` only renders the two-factor card when the feature is
on, and that card is what this package replaces. Turning the feature off removes
the page this package renders into, which is why `doctor` checks it rather than
leaving you with a working install and no visible UI.

`updatePasswords` is a separate requirement: it registers
`nova.password.confirm`, the route that guards every destructive action here.

### Why the challenge divert is superseded rather than removed

With the feature enabled, Fortify's `RedirectIfTwoFactorAuthenticatable` stays in
Nova's login pipeline and diverts any user holding a `users.two_factor_secret`
to Fortify's own challenge, before a single line of this package runs. The
obvious remedy is to delete those columns. That is the wrong remedy.

An application running a separate Fortify two-factor on another guard — a
customer-facing front end sharing the user table — keeps its live enrollments in
those same columns. Deleting them un-enrols every one of those users. So the
action is superseded instead: credential validation is untouched, a failed login
still fires `Failed`, still counts against the login limiter, still throws the
same validation exception. Only the divert changes.

### Why middleware is registered on both Nova groups

`nova.middleware` covers the Inertia pages; `nova.api_middleware` covers every
`nova-api/*` resource, action and metric endpoint. 1.x guarded only the pages,
so enforcement could be sidestepped entirely by talking to the API directly.
`doctor` verifies both, and it verifies the router's compiled groups rather than
the config arrays — Nova compiles those into router groups during its own
provider's boot, and a later config write lands in an array nothing reads.

## Cryptography and storage

### Why recovery codes are SHA-256 and not bcrypt

Deliberately the opposite of the password case. A code carries ~119 bits of
entropy, so a slow KDF buys nothing the entropy has not already bought — and it
costs the unique index.

With bcrypt you must load every row for a user and loop `Hash::check()`, which
under credential stuffing is a CPU-exhaustion vector. SHA-256 plus a unique
index makes verification one indexed equality lookup followed by
`hash_equals()`. This is the same reasoning Laravel applies to password reset
tokens and API tokens.

### Why replay protection lives in the database

The accepted TOTP timestep is claimed with a conditional `UPDATE`:

```sql
UPDATE two_factor_methods
   SET last_timestep = ?
 WHERE id = ? AND (last_timestep IS NULL OR last_timestep < ?)
```

The affected-row count decides who won, so it is correct under concurrent
requests and across application servers, with no lock and no transaction. It
also survives a cache flush, unlike a cache-based scheme.

Note that the library's own `verifyKeyNewer()` compares with `>=` and will
re-accept the same timestep, so it cannot be relied on for this.

### Why a verified session, not a password, guards factor changes

The two-factor route prefix is exempt from the challenge middleware, and has to
be: the challenge screen lives there. Every route under it that hands out or
destroys a factor therefore carries `RequireVerifiedSession`, which demands the
session have cleared a challenge, or arrived on a trusted device.

A password confirmation is not a substitute. The attacker this defends against
is holding the password already; without the second check they could enrol a
factor of their own, confirm it, and be waved through as verified — or
regenerate recovery codes and answer the challenge with one. The single
exception is an account with nothing enrolled yet, which has no second factor to
prove. That is the bootstrap case, and the only one.

### Why the relying-party ID never comes from the request

The WebAuthn relying-party ID is derived from `config('app.url')` and validated
at boot as a registrable parent of the application host. Taking it from the
`Host` header would let an attacker who controls a proxy or a vhost mint
credentials bound to a domain of their choosing.

## Rate limiting

Every limiter is keyed on **both** the user and the IP. An IP-only limit lets a
botnet spread an attack across hosts; a user-only limit lets one host walk the
whole user table.

### Why recovery codes get a looser budget

The recovery path is reached *because* the usual factor is gone, so sharing the
challenge's five-a-minute meant the attempt that mattered most was often already
spent. A code carries ~119 bits: the entropy is what stops it being guessed, not
the limit. So input shaped like a code gets a generous allowance, input that is
not gets a tight one, and the lockout never escalates.

A break-glass path that ends in a twelve-hour wait ends in a support call
instead, which is a weaker check than the code would have been.

### Why the challenge bucket splits by browser

Keying only on the account makes the limiter a weapon. Somebody holding a leaked
password cannot pass the challenge, but they can spend the owner's budget until
the owner is locked out of their own laptop — and under `required`, a locked
account is a person who cannot work.

So a browser that has cleared a challenge for that account before gets a budget
of its own, and every other browser shares the account's. The marker is a cookie
carrying an HMAC over the identifier, the account and `APP_KEY`: one that was
not issued here does not verify and falls back to the shared bucket, so an
attacker cannot mint fresh buckets to escape the limit.

The cookie grants nothing. Skipping the challenge is what a *trusted device*
does, and that is a different cookie with a row behind it. A browser the owner
has never used is in the shared bucket like everybody else, so an attack still
keeps them out of a brand-new machine; the admin reset is the way back in.

Set `rate_limits.per_device` to `false` for one bucket per account.

## Rendering

### Why the challenge screens are Blade, not Inertia

Nova resolves the initial Inertia component inside `Nova.countdown()`, which runs
*before* any tool script executes. `Nova.inertia()` therefore always registers
too late for a cold page load, and a tool-registered page renders
`pages/Loading.vue` forever. A two-factor challenge is always a cold load, so it
cannot be an Inertia page.

Being Blade also means the login path survives a JavaScript failure, which for a
login screen is a requirement rather than a nicety.

### Why the compliance page is a dashboard, not a tool page

The same reason, applied to a page an administrator will bookmark. Dashboards
are rendered by Nova's own page component, which does cold-load; the cards are
ordinary Vue components resolved by name at render time.

### How pre-authentication strings are translated

A Blade login screen has no Nova instance, so `Nova.config('translations')` is
unavailable to the script that runs there. The strings that JavaScript writes
into the page after load travel with the page instead, in `window.__n2fLang`.

Notifications capture the locale at construction rather than at send. They are
queued, and a queue worker has no request to read a locale from — it would
otherwise fall back to `app.locale` and send every mail in the wrong language on
an application that picks the language per request.

## Enforcement policy

### Why `required` cannot snooze and `encouraged` can

Under `encouraged` the prompt can be silenced for `enforcement.remind_every_days`
days, because nothing happens at the end of them. Under `required` it cannot: a
countdown that can be muted past its own deadline is not a countdown. Deferring
there buys the current session only, and once the deadline passes the endpoint
refuses outright — an escape hatch that survives its own deadline is the way
around enforcement, not a courtesy.

### Why the snooze is stored against the account

A cookie would restart the nagging every time somebody cleared their browser,
and would let a user avoid the prompt indefinitely from a fresh profile. The
preference is a decision about the account, so it lives with the account.

### Why grace has two shapes and no extra column

`days` measures from each account's `created_at`, giving every new joiner the
same runway. `date` is a single deadline everybody shares — "everybody must
comply by 1 October". Both are derived at read time, so neither needs a column,
and switching between them is a config change rather than a migration.

An earlier version treated the fixed date as the *start* of the grace window,
which quietly gave every account the cutover date plus the window on top. The
label said deadline; the behaviour said start.

### Why `enforcement.except` is matched with `Str::is`

Patterns are matched with `Str::is`, which means `*` crosses slashes. That makes
the setting expressive and dangerous in the same stroke: `['nova-api/*']` leaves
every resource, action and metric endpoint open while the pages still redirect.
`doctor` prints the effective list and fails on any pattern that reaches the
dashboard, a resource page or the Nova API, because nothing else would notice.

## Compliance measurement

### Why the default population is the guard's model

It is right for an ordinary application and wrong for a panel only
administrators can reach. Counting people who can never sign in to Nova inflates
the adoption figure, and an inflated compliance number is worse than no number:
it is read as reassurance.

`nova.compliance.models` names the populations instead.

### Why models are class names in config and closures on the tool

`config:cache` cannot serialise a closure, so the config key takes class names
only. Narrowing a population needs a query callback, which is free to live in a
service provider — hence `NovaTwoFactor::make()->audit(Admin::class, fn ($query) => …)`.
Both sources merge, and a runtime registration wins for the same class: config
states the population, a provider refines it.

### Why the mode decides which figures are computed

Three figures are not merely quiet in the wrong mode, they are wrong. Under
`optional` nothing is due, so nothing can be overdue; a coverage strip where
every row reads "not required" is one band carrying no information; and there
are no trusted-device grants to undo where nothing is enforced.

The server decides which sections to send, and a section it does not send is one
it does not compute — so `optional` never makes the grace-window pass over the
user table. Each mode also carries one figure that only means something in it:
*blocked at the door* under `required`, *reminders sent* under `encouraged`,
*new enrollments* under `optional`.

### Why the tiles filter the queue

Each resilience figure names a set of people, and the row that answers "who is
this?" is already on the page. The rows carry their tile membership, computed in
the same pass as the tiles themselves, so a figure and its filtered list cannot
disagree.

## The settings page

### Why it is off by default

A fresh install should not hand everybody who reaches Nova the ability to weaken
two-factor policy. `NOVA_TWO_FACTOR_SETTINGS=true` is a deliberate act by
whoever owns the deployment.

### Why the allow-list is an allow-list

Only the keys named in `Settings\SettingSchema` can be written, and the line is
drawn at blast radius rather than convenience:

| Not editable | Why |
|---|---|
| `enabled` | The hard switch. Pausing is the reversible one |
| `settings.editable` | The switch that grants this page its powers |
| `nova.admin_gate` | Editing who may administer this, from the page the gate protects, is privilege escalation with extra steps |
| `webauthn.relying_party.*` | A typo silently invalidates every passkey in the estate |
| `database.*` | Connection and table names |
| `fortify.supersede_challenge` | Changes the login pipeline |
| `nova.compliance.models` | Class names are code |
| `rate_limits.*` | Loosening these is how an attacker buys attempts |

### Why panel values outrank `.env`

A decision made in the UI is the most recent statement of intent, so it wins. The
cost is that `.env` can then disagree with what is in force — somebody reads
`NOVA_TWO_FACTOR_MODE=encouraged` during an incident and believes it. So a value
that differs from the deployment's is marked in the interface, and **Restore
defaults** clears every stored value at once.

Cleared, not rewritten with today's defaults: a later deploy that changes one of
those variables should be followed again, which writing the current value back
would silently prevent.

### Why a pause expires, survives a deploy, and is attributed

Three properties, each one a failure someone has had:

- **It expires by itself.** A security control switched off on a Friday
  afternoon is the one nobody remembers on Monday.
- **It survives a deploy.** Stored in the database rather than the cache, so a
  cache flush mid-incident cannot silently re-arm the gate under whoever is
  halfway through fixing it.
- **It is attributed.** Who, why, until when — and audited as suspicious,
  because for its duration every account is protected by a password alone.

It does not clear anyone's verified session. Pausing is not a logout.

## Administrative access

### Why the admin pages start closed

`nova.admin_gate` ships pointing at `nova-two-factor:admin`, an ability no fresh
application defines — and `Gate::allows()` denies an ability that does not
exist. Until the host application says who may see them, the overview, the
settings page, the activity log and the reset action are reachable by nobody,
and the menu entry does not render.

The alternative default — open to every Nova user — was rejected on what these
pages carry: every account's enrolment status, the addresses behind them, and a
list of exactly which people are one lost device away from a lockout. In most
applications everyone on staff can reach Nova, and everyone on staff is the
wrong audience for that.

Opening them is a one-line deliberate act (`'admin_gate' => null`), which is the
right shape for this decision: a single-administrator panel should be able to
say "yes, everyone", but should have to say it.

### Why the gate is checked on the endpoint, not only the page

Hiding a menu entry is not access control. Each dashboard authorises itself, and
the JSON endpoints behind them authorise again — the URLs are guessable, and the
data is the part worth guarding.

## Mail

### Why reminder links do not use `APP_URL`

Where Nova has a domain of its own — an admin panel on `hub.example.com` beside
a customer application on `app.example.com` — `APP_URL` names the second. A
reminder that sends an administrator to the customer site to set up their
*admin* second factor is worse than one with no link at all.

`Support\PanelUrl` resolves the host the mail was sent from (captured while a
request exists, so it survives the queue), then `nova.domain`, then `APP_URL`.

### Why there are two reminder mails

`required` has a date and something happens on it; `encouraged` has neither. The
same mail for both would either print a deadline nothing enforces, or omit one
that matters. A deadline nobody enforces teaches people the next one is not real
either.

### Why SMS is not shipped

The `OtpTransport` contract is the documented extension point, but shipping SMS
means shipping a toll-fraud surface. It is left to applications that need it.

## Extending the package

### Hearing about a lockout burst

A lockout is per account, expires on its own, and escalates only for the account
being guessed at — so several at once means somebody is working through a list
of stolen passwords. Nothing about that is visible unless you go looking.

```php
// config/nova-two-factor.php
'alerts' => ['lockout_burst' => ['accounts' => 5, 'window_minutes' => 15]],

// A service provider
Event::listen(LockoutBurstDetected::class, function ($event): void {
    Log::critical("{$event->accounts} accounts locked out in {$event->windowMinutes} minutes.");
});
```

Three decisions in that feature are worth stating. It counts **distinct
accounts**, so one person fumbling their authenticator never trips it. It fires
**once per burst** rather than per lockout, because an alert that arrives fifty
times is an alert people filter. And it ships with the threshold at `0`, meaning
silent: a package that starts logging `critical` into somebody else's monitoring
without being asked is a package that gets its alerting ignored.

### Add your own factor type

```php
use Gabrielesbaiz\NovaTwoFactor\Facades\TwoFactor;

TwoFactor::extend('yubico-otp', fn ($app) => new YubicoDriver(...));
```

Implement `Contracts\TwoFactorMethodDriver`. Enrollment must be idempotent for
the lifetime of a pending enrollment, or refreshing the setup page will
invalidate the QR the user just scanned.

### Send email codes over SMS

```php
$this->app->bind(OtpTransport::class, TwilioOtpTransport::class);
```

### React to two-factor events

```php
use Gabrielesbaiz\NovaTwoFactor\Contracts\AuditableEvent;

Event::listen(AuditableEvent::class, function (AuditableEvent $event) {
    // Every two-factor event, through one interface.
});
```

Listen on the interface, not on the base class. Laravel's dispatcher resolves
listeners through `class_implements()`, so a listener bound to a parent *class*
never fires for its subclasses.

---

[← Back to the README](README.md) · [Upgrading from 1.x](UPGRADE.md) ·
[Reporting a vulnerability](SECURITY.md)
