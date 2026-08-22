<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor;

use Gabrielesbaiz\NovaTwoFactor\Contracts\AuditableEvent;
use Gabrielesbaiz\NovaTwoFactor\Listeners\WriteAuditLog;
use Gabrielesbaiz\NovaTwoFactor\Otp\OtpCodeManager;
use Gabrielesbaiz\NovaTwoFactor\RateLimiting\RegistersRateLimiters;
use Gabrielesbaiz\NovaTwoFactor\Recovery\RecoveryCodeManager;
use Gabrielesbaiz\NovaTwoFactor\Support\Enforcement;
use Gabrielesbaiz\NovaTwoFactor\Totp\QrCodeGenerator;
use Gabrielesbaiz\NovaTwoFactor\Totp\TotpProvider;
use Gabrielesbaiz\NovaTwoFactor\WebAuthn\AaguidRegistry;
use Gabrielesbaiz\NovaTwoFactor\WebAuthn\CeremonyStore;
use Gabrielesbaiz\NovaTwoFactor\WebAuthn\RelyingParty;
use Gabrielesbaiz\NovaTwoFactor\WebAuthn\WebAuthnService;
use Illuminate\Support\Facades\Event;
use PragmaRX\Google2FA\Google2FA;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class NovaTwoFactorServiceProvider extends PackageServiceProvider
{
    use RegistersRateLimiters;

    public function configurePackage(Package $package): void
    {
        $package
            ->name('nova-two-factor')
            ->hasConfigFile()
            ->hasTranslations()
            ->hasViews()
            ->hasMigration('create_two_factor_tables')
            ->hasCommands([
                Console\DoctorCommand::class,
                Console\PruneCommand::class,
                Console\ResetCommand::class,
                Console\UpgradeCommand::class,
            ]);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(Enforcement::class);
        $this->app->singleton(RecoveryCodeManager::class);
        $this->app->singleton(QrCodeGenerator::class);

        $this->app->singleton(OtpCodeManager::class);
        $this->app->singleton(AaguidRegistry::class);

        $this->app->singleton(TotpProvider::class, static fn (): TotpProvider => new TotpProvider(new Google2FA));

        // Resolved per request rather than as a singleton: the relying party is
        // derived from configuration that a host app may legitimately swap at
        // runtime (multi-tenant installs on different hostnames).
        $this->app->bind(RelyingParty::class, static fn (): RelyingParty => RelyingParty::resolve());
        $this->app->bind(WebAuthnService::class, static fn ($app): WebAuthnService => new WebAuthnService(
            $app->make(RelyingParty::class),
        ));
        $this->app->bind(CeremonyStore::class, static fn ($app): CeremonyStore => new CeremonyStore(
            $app->make('session.store'),
        ));

        $this->app->singleton(TwoFactorManager::class, static fn ($app): TwoFactorManager => new TwoFactorManager(
            $app,
            $app->make(RecoveryCodeManager::class),
            $app->make(Enforcement::class),
        ));
    }

    public function packageBooted(): void
    {
        $this->registerRateLimiters();

        // One listener for the whole event surface, so the audit trail cannot
        // drift out of step with the events as new ones are added.
        Event::listen(AuditableEvent::class, WriteAuditLog::class);

        // Registered here rather than in composer's provider list so it always
        // boots *after* Nova's own provider. Container rebindings and Nova
        // config edits both depend on winning the last write, and provider
        // order is the only thing that decides that.
        $this->app->register(ToolServiceProvider::class);
    }
}
