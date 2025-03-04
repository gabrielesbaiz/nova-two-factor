# NovaTwoFactor

[![Latest Version on Packagist](https://img.shields.io/packagist/v/gabrielesbaiz/nova-two-factor.svg?style=flat-square)](https://packagist.org/packages/gabrielesbaiz/nova-two-factor)
[![Total Downloads](https://img.shields.io/packagist/dt/gabrielesbaiz/nova-two-factor.svg?style=flat-square)](https://packagist.org/packages/gabrielesbaiz/nova-two-factor)

Laravel nova in-dashboard 2FA feature.

Original code from [Visanduma/nova-two-factor](https://github.com/Visanduma/nova-two-factor)

## Features

- ✅ Global enable / disable
- ✅ Mandatory / Not mandatory
- ✅ Google 2FA encrypted
- ✅ BancodeQrCode / Google API

## Installation

You can install the package via composer:

```bash
composer require gabrielesbaiz/nova-two-factor
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="nova-two-factor-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="nova-two-factor-config"
```

This is the contents of the published config file:

```php
return [
    'enabled' => env('NOVA_TWO_FA_ENABLE', true),

    'mandatory' => env('NOVA_TWO_FA_MANDATORY', false),

    'user_table' => 'users',

    'user_id_column' => 'id',

    'connection_name' => env('DB_CONNECTION'),

    /* Encrypt the google secret values saved in database */
    'encrypt_google2fa_secrets' => false,

    /* QR code can be generate using  Google API or inbuilt 'BaconQrCode' package */
    'use_google_qr_code_api' => true,

    'user_model' => App\Models\User::class,

    /* Change visibility of Nova Two Fa menu in right sidebar */
    'showin_sidebar' => true,

    'menu_text' => 'Two FA',

    'menu_icon' => 'lock-closed',

    /* Exclude any routes from 2fa security */
    'except_routes' => [],

    /*
     * reauthorize these urls before access, within given timeout
     * you are allowed to use wildcards pattern for url matching
     */
    'reauthorize_urls' => [
        // 'nova/resources/users/new',
        // 'nova/resources/users/*/edit',
    ],

    /* timeout in minutes */
    'reauthorize_timeout' => 5,
];
```

## Usage

1. Pubish config & migration

2. Use ProtectWith2FA trait in configured model

```php

namespace App\Models;

use Gabrielesbaiz\NovaTwoFactor\ProtectWith2FA;

class User extends Authenticatable{

    use ProtectWith2FA;
}
```

3. Add TwoFa middleware to nova config file

```php
/*
    |--------------------------------------------------------------------------
    | Nova Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will be assigned to every Nova route, giving you the
    | chance to add your own middleware to this stack or override any of
    | the existing middleware. Or, you can just stick with this stack.
    |
    */

    'middleware' => [
        ...
        \Gabrielesbaiz\NovaTwoFactor\Http\Middleware\TwoFa::class
    ],

```

4. Register NovaTwoFactor tool in Nova Service Provider

```php
<?php

class NovaServiceProvider extends NovaApplicationServiceProvider{

public function tools()
    {
        return [
            ...
            new \Gabrielesbaiz\NovaTwoFactor\NovaTwoFactor()

        ];
    }

}

```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Visanduma R & D](https://github.com/Visanduma)
- [Gabriele Sbaiz](https://github.com/gabrielesbaiz)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
