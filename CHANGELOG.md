# Changelog

All notable changes to `nova-two-factor` are documented here.

## 2.0.0 — unreleased

A complete rewrite. **Read [UPGRADE.md](UPGRADE.md) before upgrading**, and treat
every 1.x TOTP secret as compromised.

### Security

Fixes, each with a regression test:

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
- `doctor`, `reset`, `prune` and `upgrade` artisan commands.
- 133 tests, PHPStan level 6, and CI across PHP 8.2–8.4, Laravel 11–12, MySQL and
  Postgres.

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
- The committed `dist/` directory, now built in CI with a staleness check.

### Requirements

- PHP 8.2+, Laravel 11 or 12, Nova 5.7+.
