<?php

declare(strict_types=1);

arch('no debugging helpers ship')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

arch('every source file declares strict types')
    ->expect('Gabrielesbaiz\NovaTwoFactor')
    ->toUseStrictTypes();

arch('comparisons in security code are timing-safe or strict')
    ->expect(['strcmp', 'strcasecmp', 'substr_compare'])
    ->not->toBeUsed();

arch('config is read through the container, never env(), outside config files')
    ->expect('Gabrielesbaiz\NovaTwoFactor')
    ->not->toUse('env');

arch('models stay in the Models namespace')
    ->expect('Gabrielesbaiz\NovaTwoFactor\Models')
    ->toExtend(Illuminate\Database\Eloquent\Model::class)
    ->ignoring('Gabrielesbaiz\NovaTwoFactor\Models\Concerns');

arch('enums are backed, so they are safe to persist and cast')
    ->expect('Gabrielesbaiz\NovaTwoFactor\Enums')
    ->toBeStringBackedEnums();

arch('no remote QR-code service is ever contacted')
    // The single worst defect in 1.x: the default configuration handed every
    // TOTP secret to api.qrserver.com inside a GET query string.
    ->expect('Gabrielesbaiz\NovaTwoFactor')
    ->not->toUse(['file_get_contents', 'curl_init', 'curl_exec']);

arch('events are auditable through the interface, not a base class')
    ->expect('Gabrielesbaiz\NovaTwoFactor\Events')
    ->toImplement(Gabrielesbaiz\NovaTwoFactor\Contracts\AuditableEvent::class);

arch('drivers all satisfy the driver contract')
    ->expect('Gabrielesbaiz\NovaTwoFactor\Drivers')
    ->toImplement(Gabrielesbaiz\NovaTwoFactor\Contracts\TwoFactorMethodDriver::class);

arch('result objects are immutable')
    ->expect('Gabrielesbaiz\NovaTwoFactor\Results')
    ->toBeReadonly();
