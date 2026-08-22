# Contributing

## Getting set up

Nova is a paid, private Composer package, so you will need a licence.

```bash
composer config --global http-basic.nova.laravel.com "you@example.com" "your-licence-key"
composer install
npm ci && npm run build
```

Then:

```bash
composer test      # Pest
composer analyse   # PHPStan
composer format    # Pint
```

## Ground rules

**Every security control needs a test that fails without it.** The suite carries
a regression test for each vulnerability found in 1.x, and each one names the
defect it guards against. If you add a control, add the test that proves it; if
you change one, the existing test should tell you what you broke.

**No remote calls.** `Http::preventStrayRequests()` is on for the whole suite. A
QR code, a code hash and a credential check all happen locally, and an
architecture test asserts the package does not reach for `curl` or
`file_get_contents` at all.

**Secrets never leave the server.** Anything that could carry one — a response
body, an audit row, a notification payload, a log line — needs a test asserting
it does not. `TwoFactorAudit` throws outside production if a context key even
looks like a secret.

**Prefer a conditional `UPDATE` over read-then-write** for anything single-use.
Replay protection, recovery-code consumption and counter checks are all claimed
by affected-row count, which is correct under concurrency without a lock.

## Running against a real database

SQLite cannot exercise the decisions that only matter on a real driver — the
64-character credential-ID hash index, JSON columns, conditional-update
semantics. CI runs MySQL 8.4 and Postgres 16; you can too:

```bash
DB_CONNECTION=mysql DB_DATABASE=testing vendor/bin/pest
```

## Front end

Two bundles, built in two passes because an IIFE cannot take multiple entries:

- `tool` runs inside Nova's SPA and treats Nova's globals as externals, so our
  components share Nova's Vue instance
- `challenge` runs on the server-rendered pre-authentication pages and is
  deliberately dependency-free — those pages are always a cold load and sit on
  the login path

There is **no Tailwind build**. Nova already ships compiled, unprefixed Tailwind
on every page; `resources/css/tool.css` adds only what Nova has no utility for,
built on Nova's `--colors-*` custom properties. Adding a second Tailwind build is
what made the 1.x UI look foreign inside Nova, so please do not reintroduce one.

`dist/` is gitignored during development and built in CI. A workflow fails the
build if a committed bundle has drifted from its source.

## Pull requests

1. Branch from `main`.
2. Keep the three gates green: Pest, PHPStan, Pint.
3. Describe the behaviour change, not just the diff.
4. Update `CHANGELOG.md`.

Security issues go to the address in [SECURITY.md](SECURITY.md), not to a public
pull request.
