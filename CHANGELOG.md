# Changelog

All notable changes to `nova-two-factor` are documented here.

## 2.2.0 — 2026-10-09

### Added

- `methods.webauthn.unsupported_user_agents`: a list of case-insensitive
  User-Agent substrings for which passkeys stop being offered. When the current
  request matches one, `WebAuthnDriver::isAvailable()` reports false and the
  method disappears from enrollment, exactly as if it were switched off in
  config.

  The case this exists for is Nova embedded in a native application. On Apple
  platforms WebAuthn inside a `WKWebView` is gated behind the restricted
  `com.apple.developer.web-browser.public-key-credential` entitlement, granted
  only to browsers on review — the ceremony never reaches an authenticator and
  returns a bare `NotAllowedError`. That is the same error a dismissed prompt
  produces, so the screen could only report "cancelled or timed out": a button
  that can never work, under an explanation that says the user changed their
  mind. Withdrawing the offer is the honest answer, and the User-Agent is the
  only signal available before the ceremony starts.

  Empty by default, so nothing changes until the embedding application names
  its own client. Evaluated only when there is a request to read, so console
  commands and queued work are never filtered.

- The login challenge and the step-up screen stop *listing* a factor the client
  cannot run — the case that actually matters, since anyone meeting this
  enrolled their passkey from a desktop browser and is now inside the embedded
  view with it offered as their default.

  Guarded: the factor is withdrawn only while the user has another way in — a
  second confirmed method, or, on the challenge screen, a remaining recovery
  code. Recovery codes do not count on a step-up, which offers no recovery
  route by design. If a passkey is the only thing an account holds, it stays on
  screen: a button that fails can be worked around by opening a real browser,
  and an empty screen cannot be worked around by anybody.

  Presentation only. `prepare` and `store` are untouched on both screens, so a
  direct link, a stale tab or a client this guesses wrong about can still
  attempt the ceremony — the same rule `TwoFactorManager::verify()` follows,
  where disabling a method never shuts out somebody who already enrolled it.
  New `Support\OfferedMethods` decides what a proving screen shows, and a new
  `Contracts\ClientDependent` is what separates "cannot be run here" from
  "switched off in config"; only the former hides an enrolled factor.

## 2.1.2 — 2026-09-28

### Changed

- A guarded Nova request no longer asks the same question three times.
  `hasTwoFactorEnabled()` is consulted once by `RequireTwoFactor` and twice more
  by `RequireTwoFactorEnrollment` — through `blocks()`, then through
  `shouldRemind()` or `shouldWarn()` — and each call issued its own `exists`
  query. The answer is now memoized for the request in a new
  `Support\FactorStatus`, and invalidated by both the model events and the
  package events that change it, so a factor confirmed mid-request is seen
  immediately. Three queries become one.
- The enforcement gate is evaluated once per request rather than twice.
  `appliesTo()` opens `blocks()`, `shouldRemind()`, `shouldWarn()` and
  `graceEndsAt()`, and behind a permission package the configured gate is itself
  a query. Memoized per user for the request, and dropped whenever
  `Enforcement::requireUsing()` changes who is in scope.
- The enforcement except-list is built once per request instead of on every
  middleware pass. Both middlewares call `exceptPatterns()`, which rebuilt,
  merged and de-duplicated the same array each time.

  Together these halve the queries a guarded Nova request runs before it reaches
  the application — and both middlewares are registered on `nova.api_middleware`
  as well as `nova.middleware`, so a page that fires a dozen XHRs was paying for
  all of it a dozen times over.

### Fixed

- `Enforcement` and `SettingsRepository` are bound with `scoped()` rather than
  `singleton()`. `SettingsRepository` records the value underneath each setting
  it overrides, which is per-request state; under Octane, where a singleton
  outlives the request that built it, a later request could restore a setting to
  a baseline recorded for an earlier one. Same single-instance guarantee,
  released at the end of each request.

## 2.1.1 — 2026-09-22

### Fixed

- The user security page now uses the full width of Nova's content area.
  Nova's page container applies `max-w-7xl mx-auto`; a `:has()` rule in
  `tool.css` clears `max-width` on the container holding this package's card.
  CSS only — no configuration key, no API change.

## 2.1.0 — 2026-09-21

### Added

- Laravel 13 support. `illuminate/support` now allows `^13.0`, alongside 11 and
  12. Laravel 13 itself requires PHP 8.3, so an application on PHP 8.2 simply
  resolves to Laravel 12 as before.
- Nova 5.11 is now permitted (`^5.7` in place of the `5.7.*` dev pin). Nova 5.11
  is the first release that supports Laravel 13; the `>=6.0.0` conflict stands.

### Changed

- Test matrix moved to Testbench 11 and Pest 4 for the Laravel 13 path.
- `Inertia\ServiceProvider` is now registered explicitly in the test harness.
  Nova renders through Inertia but does not register the provider itself, and
  Inertia 3 no longer arrives registered by another provider, which left
  `Inertia\Ssr\Gateway` unbound.

## 2.0.0 — 2026-09-21

A complete rewrite. **Read [UPGRADE.md](UPGRADE.md) before upgrading**, and treat
every 1.x TOTP secret as compromised.

### Security

Fixes, each with a regression test:

- **Reminder mail could be sent in a loop.** `compliance/remind` had no limiter
  and no per-recipient cooldown, so one session could mail a colleague from your
  domain as fast as the box answered — with 500 characters of caller-supplied
  text in the body. The route is now throttled, and a cooldown keyed on the
  *recipient* (`enforcement.remind_cooldown_hours`, default 24) applies to the
  bulk Nova action too, which skips and reports rather than failing the batch.
- **Recovery-code entropy is now checked rather than assumed.**
  `nova-two-factor:doctor` reports the bits per code and warns when
  `recovery_codes.length` is lowered or set below the generator's floor. The
  codes are stored as an unkeyed SHA-256 while every other secret is keyed, and
  that rests entirely on the length — so the length is verified, and the
  reasoning is written down in the code and the README rather than inferred.
- **The admin pages now start closed.** `nova.admin_gate` defaulted to null,
  which meant every user who could reach Nova could read the compliance
  dashboard and the activity log — every account's enrollment status, the
  addresses behind them, and who was one lost device from a lockout. It now
  defaults to `nova-two-factor:admin`, an ability a fresh application does not
  define, and an undefined ability denies. Define it, point the key at an
  ability you already have, or set it to null to keep the old behaviour
  deliberately. `nova-two-factor:doctor` says which state you are in.
- **`enforcement.except` had no floor and no visibility.** A pattern that was
  too broad — `*`, `nova*`, `nova-api/*` — silently disabled enforcement for
  everything it matched, with no error, no log and no symptom, while the
  middleware check reported healthy. `nova-two-factor:doctor` now prints the
  effective list and fails on any pattern that reaches the dashboard, a resource
  page or the Nova API, judged by what it matches rather than how it is spelled.
- **Cookies could ship without `Secure` on an HTTPS site.** Both package
  cookies asked `$request->isSecure()`, which is false behind a TLS-terminating
  proxy unless `TrustProxies` is configured — so the trusted-device cookie, a
  thirty-day skip past the challenge, could travel on plain HTTP and be
  replayed. They now follow `nova-two-factor.cookies.secure`, then
  `session.secure`, and only then the request. `nova-two-factor:doctor` warns
  when an HTTPS application has settled neither.
- **The recovery limiter was dead config.** `rate_limits.recovery` was
  documented and registered but wired to nothing: recovery codes spent the
  challenge budget, five a minute with an escalating lockout, on the one path
  reached because the usual factor is already gone. It now has its own bucket —
  10 attempts for input shaped like a code, 3 for input that is not, and a flat
  lockout that never doubles.
- **A leaked password can no longer lock its owner out.** The challenge limiter
  was keyed on the account alone, so an attacker who could not pass the
  challenge could still spend the owner's budget until they were locked out —
  denial of service under `required`. A browser that has cleared a challenge for
  that account now gets its own bucket, identified by an HMAC-signed cookie that
  grants nothing and cannot be forged; everything else shares the account's
  bucket as before. `rate_limits.per_device`.
- **A wave of lockouts now raises an alert.** A lockout protects one account; a
  credential dump aimed at a panel locks many, and under `required` that is a
  denial of service. Set `alerts.lockout_burst.accounts` and listen for
  `Alerts\LockoutBurstDetected`. It counts distinct accounts, fires once per
  burst, and is off until a threshold is set. Trusted devices are unaffected by
  a lockout, which is what keeps a targeted attack survivable — now covered by a
  test.
- **The challenge could be bypassed with the password alone.** The two-factor
  prefix is exempt from the challenge middleware — the challenge screen lives
  there — which left every management route under it reachable by a session that
  had never answered one. Two requests enrolled a new factor and confirmed it,
  and confirming marked the session verified; regenerating recovery codes
  returned working challenge answers behind a password prompt the attacker could
  already satisfy. Those routes now carry `RequireVerifiedSession`, and
  confirming only ever verifies a session when it is the account's *first*
  factor.

- **Unauthenticated endpoints.** 1.x registered its routes with a middleware
  stack containing no authentication, and an authorization check that defaulted
  to allow. `confirm`, `toggle`, `clear`, `recover` and `authenticate` were all
  reachable by a guest; several returned a stack trace rather than a 401.
- **TOTP secrets sent to a third party.** `use_google_qr_code_api` defaulted to
  `true`, putting the full `otpauth://` URI in a GET query string to
  `api.qrserver.com`. The remote path is deleted, not made opt-out, and an
  architecture test asserts no outbound call can be made.
- **No rate limiting.** Every code-submitting endpoint is now throttled per user
  *and* per IP, with exponential lockout and a `Retry-After` header.
- **Two-factor could be disabled with a single unauthenticated POST.** Removal
  now requires a fresh password confirmation, and is refused outright when it
  would leave a required account unprotected.
- **Secrets stored in plaintext by default.** Always encrypted now, with no
  opt-out.
- **No replay protection.** The accepted TOTP timestep is persisted and claimed
  with a conditional `UPDATE`, so a captured code cannot be reused — correct
  under concurrency and across application servers.
- **A recovery code deleted the user's entire two-factor record.** It now
  satisfies one challenge and nothing more.
- **A single recovery code, upper-cased.** Now a set of individually hashed,
  single-use codes with case preserved.
- **Challenge middleware failed open four ways** and substituted a view body
  rather than halting, leaving non-HTML endpoints usable. It now redirects.
- **Enforcement was undocumented and path-hardcoded.** Registered
  automatically, on both Nova middleware groups, with the except-list derived
  from `config('nova.path')`.
- **No session regeneration on success.** The session id is now rotated at the
  two-factor boundary, closing session fixation across it.

### Added

- Passkeys / WebAuthn, including conditional-mediation autofill on the challenge
  screen, AAGUID-derived naming, and counter-regression detection that correctly
  exempts synced passkeys.
- Email one-time codes, with destination confirmation before the method counts.
- More than one method per user, with a user-chosen default.
- Enforcement policies: `optional`, `encouraged`, `required`, with a grace period
  measured either organisation-wide or per user, and per-role targeting through a
  gate.
- Step-up re-authentication, scoped and HMAC-signed per action, answering XHR
  with 423 like Laravel's own `RequirePassword`.
- Trusted devices, revocable individually or in bulk.
- An audit trail, with a guard that refuses to write a payload resembling a
  secret.
- Nova integration: a sortable status field, reset and revoke actions, and
  adoption, method-mix and failure metrics.
- Fortify interoperability: Nova keeps Fortify's pre-authentication two-factor
  divert in its login pipeline while the feature flag is on, which sent anyone
  holding a `users.two_factor_secret` to Fortify's challenge before this package
  ran. That action is now superseded (`fortify.supersede_challenge`), so the
  challenge stays here. Credential validation, the `Failed` event and the login
  rate limiter are untouched. An application running a separate Fortify
  two-factor on another guard therefore keeps its own enrollments in those
  columns instead of having to delete them.
- `doctor`, `reset`, `prune` and `upgrade` artisan commands.
- 153 tests and PHPStan level 6, run locally via `composer test` and
  `composer analyse`.

### Changed

- The management UI replaces Nova's own security card in place, rather than
  living on a separate tool page.
- The challenge, step-up and enrollment screens are server-rendered and work
  without JavaScript. A tool-registered Inertia page cannot survive a cold load,
  which is what a challenge always is.
- No Tailwind build. Nova's compiled stylesheet is reused, so the UI inherits the
  dashboard's tokens and theme.
- Vite replaces Laravel Mix, producing two bundles.
- Polymorphic storage, so any number of authenticatable models are supported.

### Removed

- `pragmarx/google2fa-laravel`, whose session model was the source of several
  findings. The algorithm library `pragmarx/google2fa` is kept.
- Every 1.x configuration key. See [UPGRADE.md](UPGRADE.md) for the mapping.
- `ProtectWith2FA`, replaced by `HasTwoFactorAuthentication`.
- `pragmarx/google2fa-laravel`. The compiled `dist/` is still committed, since a
  Nova tool cannot function without it; `composer build-assets` rebuilds it and
  reports drift before a tag.

### Requirements

- PHP 8.2+, Laravel 11 or 12, Nova 5.7+.
- `pragmarx/google2fa` 8 or 9. The constraint accepts both, so the package can be
  installed beside a Fortify recent enough to have moved to 9.
