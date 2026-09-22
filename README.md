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

### 📖 [Read the documentation →](https://gabrielesbaiz.github.io/nova-two-factor/)

Installation, every configuration option, a builder that writes your `.env`, an
enforcement simulator, and every screen in both themes.

> [!CAUTION]
> **Upgrading from 1.x?** Read [UPGRADE.md](UPGRADE.md) first. 1.x had critical
> vulnerabilities, including unauthenticated endpoints and TOTP secrets sent to
> a third-party QR service by default. **Treat every 1.x secret as compromised**
> and have users re-enrol.

> [!IMPORTANT]
> A ⭐ costs you nothing and helps other developers find this package.
> [Sponsoring](https://github.com/sponsors/gabrielesbaiz) keeps it compatible
> with every new Laravel release.

## What it does

Nova ships two-factor authentication of its own through Laravel Fortify. If
authenticator applications and recovery codes for users who opt in are all you
need, use it. This package exists for what that cannot do:

- **Passkeys** and **email one-time codes**, alongside authenticator apps.
- **More than one method per user**, so a lost phone is not a lost account.
- **Mandatory enrollment** with a grace period, and per-role targeting.
- **Step-up re-authentication** in front of dangerous actions.
- **Trusted devices**, an **audit trail**, and three **administration pages**.

Recovery codes are hashed individually, TOTP replay is refused, and QR codes are
generated locally — there is no remote code path.

## Requirements

- PHP 8.2+ (8.3+ for Laravel 13)
- Laravel 11, 12 or 13
- Nova 5.7+ (5.11+ for Laravel 13)
- `https` in production. Passkeys require a secure context; `localhost` is exempt.

## Installation

```bash
composer require gabrielesbaiz/nova-two-factor

php artisan vendor:publish --tag=nova-two-factor-migrations
php artisan migrate
php artisan vendor:publish --tag=nova-two-factor-config
php artisan vendor:publish --tag=nova-two-factor-assets

php artisan nova-two-factor:doctor
```

Then add the `HasTwoFactorAuthentication` trait to your authenticatable models
and register `NovaTwoFactor::make()` in your `NovaServiceProvider`. Fortify's
`updatePasswords` and `twoFactorAuthentication` features must stay enabled.

**[Full installation guide →](https://gabrielesbaiz.github.io/nova-two-factor/#/installation)**

## Artisan commands

| Command | Purpose |
|---|---|
| `nova-two-factor:doctor` | Check the configuration. Non-zero exit on failure. |
| `nova-two-factor:reset {user}` | Break-glass reset: methods, codes, devices, lockouts, sessions and reminder snooze. |
| `nova-two-factor:prune` | Remove expired challenges, devices and old audit rows. |
| `nova-two-factor:upgrade` | Port 1.x data. See [UPGRADE.md](UPGRADE.md). |

## Documentation

| | |
|---|---|
| [Documentation site](https://gabrielesbaiz.github.io/nova-two-factor/) | Everything: install, configure, operate. |
| [DESIGN.md](DESIGN.md) | Why the package works the way it does. |
| [UPGRADE.md](UPGRADE.md) | Upgrading from 1.x. Read before you start. |
| [AGENTS.md](AGENTS.md) | For AI assistants installing or configuring this. |
| [SCREENSHOTS.md](SCREENSHOTS.md) | Every screen, light and dark. |
| [CHANGELOG.md](CHANGELOG.md) | What changed, and when. |

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

## Security vulnerabilities

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

## Support this package

If it is useful to you:

- ⭐ **Star the repo.** Free, thirty seconds, and it is the first signal other developers look at.
- ❤️ **[Become a sponsor](https://github.com/sponsors/gabrielesbaiz).** From $5 a month.
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
limitation of liability apply in full, alongside the disclaimer above.
