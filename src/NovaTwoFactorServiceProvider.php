<?php

namespace Gabrielesbaiz\NovaTwoFactor;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class NovaTwoFactorServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('nova-two-factor')
            ->hasConfigFile()
            ->hasMigration('create_nova_two_factor_table');
    }
}
