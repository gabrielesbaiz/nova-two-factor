# Security Policy

## Supported versions

| Version | Supported |
|---|:---:|
| 2.x | ✅ |
| 1.x | ❌ — see below |

## 1.x is not supported and should not be used

A review of 1.x found unauthenticated endpoints, TOTP secrets transmitted to a
third-party QR service by default, no rate limiting on code submission, and a
two-factor disable path with no re-authentication. There is no patch release;
[upgrade to 2.x](UPGRADE.md) and treat existing secrets as compromised.

## Reporting a vulnerability

Email **gabriele.sbaiz@noviasnet.it** with:

- a description of the issue and what it lets an attacker do
- the affected version
- reproduction steps or a proof of concept, if you have one

Please do **not** open a public issue for anything exploitable.

You can expect an acknowledgement within 5 working days, and an assessment with
a fix timeline within 15. Reporters are credited in the release notes unless they
prefer otherwise.

## Scope

In scope: authentication bypass, replay, privilege escalation, secret disclosure,
rate-limit bypass, enforcement bypass, and anything that lets one user act on
another's second factor.

Out of scope: findings that require an already-compromised application key,
missing headers with no demonstrated impact, and vulnerabilities in Nova or
Laravel themselves — report those to their maintainers.

## Design notes for reviewers

Documented deliberate decisions, so they are not re-reported as bugs:

- **Recovery codes use SHA-256, not bcrypt.** ~119 bits of entropy makes a slow
  KDF pointless, while a unique index turns verification into one lookup instead
  of a loop over every row — which is otherwise a CPU-exhaustion vector.
- **Email OTP codes use HMAC-SHA256 keyed on `APP_KEY`.** A six-digit code is
  ~20 bits; an unkeyed digest of a leaked table is trivially reversed.
- **A zero WebAuthn signature counter is not treated as a regression.** Every
  synced passkey reports a permanent zero.
- **Step-up grants are signed even though they live in the session.** The
  `cookie` session driver keeps the payload client-side, and signing binds a
  grant to its scope, user and session.
