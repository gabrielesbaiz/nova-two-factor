<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor;

use Gabrielesbaiz\NovaTwoFactor\Contracts\AuditableEvent;
use Gabrielesbaiz\NovaTwoFactor\Events\LockedOut;
use Gabrielesbaiz\NovaTwoFactor\Listeners\DetectLockoutBurst;
use Gabrielesbaiz\NovaTwoFactor\Listeners\WriteAuditLog;
use Gabrielesbaiz\NovaTwoFactor\Otp\OtpCodeManager;
use Gabrielesbaiz\NovaTwoFactor\RateLimiting\RegistersRateLimiters;
use Gabrielesbaiz\NovaTwoFactor\Recovery\RecoveryCodeManager;
use Gabrielesbaiz\NovaTwoFactor\Settings\Pause;
use Gabrielesbaiz\NovaTwoFactor\Settings\SettingsRepository;
use Gabrielesbaiz\NovaTwoFactor\Support\Enforcement;
use Gabrielesbaiz\NovaTwoFactor\Support\TwoFactorSession;
use Gabrielesbaiz\NovaTwoFactor\Totp\QrCodeGenerator;
use Gabrielesbaiz\NovaTwoFactor\Totp\TotpProvider;
use Gabrielesbaiz\NovaTwoFactor\WebAuthn\AaguidRegistry;
use Gabrielesbaiz\NovaTwoFactor\WebAuthn\CeremonyStore;
use Gabrielesbaiz\NovaTwoFactor\WebAuthn\RelyingParty;
use Gabrielesbaiz\NovaTwoFactor\WebAuthn\WebAuthnService;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
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
            ->hasMigration('add_two_factor_settings_table')
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
        $this->app->singleton(SettingsRepository::class);
        $this->app->singleton(Pause::class);
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
        $this->publishAssets();

        // The overlay goes into config before anything reads it — middleware
        // registration below included, since that is driven by `enabled`.
        // Rescued rather than guarded: this runs on a fresh install where the
        // table does not exist yet, and a package that fatals before `migrate`
        // can finish is a package nobody can install.
        rescue(fn () => $this->app->make(SettingsRepository::class)->apply(), report: false);

        $this->registerRateLimiters();

        // One listener for the whole event surface, so the audit trail cannot
        // drift out of step with the events as new ones are added.
        Event::listen(AuditableEvent::class, WriteAuditLog::class);

        // A lockout protects one account; a wave of them denies a panel. The
        // individual rows are already in the log, so this only watches for the
        // shape, and stays silent until an operator sets a threshold.
        Event::listen(LockedOut::class, DetectLockoutBurst::class);

        // Nova's own logout invalidates the session, but the package cannot
        // depend on that: an application with a custom logout, or one that only
        // calls `Auth::logout()`, would leave `nova_two_factor.passed_at` in
        // place — and the next login in that session would walk past the
        // challenge. Cheap, and it closes the hole wherever logout lives.
        // A login is the start of a session's life as that user, so whatever a
        // previous occupant of the session cleared does not carry into it.
        // Belt to the logout listener's braces: the two together mean a
        // verification cannot outlive the sign-in it belongs to, whichever end
        // the host application's own auth flow happens to touch.
        Event::listen(Login::class, function (Login $event): void {
            if (! $this->app->bound('session.store')) {
                return;
            }

            (new TwoFactorSession($this->app->make('session.store')))->clear();
        });

        Event::listen(Logout::class, function (Logout $event): void {
            // Resolved from the container rather than the request, because a
            // logout can also come from a console command or a job, where there
            // is no request to ask.
            if (! $this->app->bound('session.store')) {
                return;
            }

            (new TwoFactorSession($this->app->make('session.store')))->clear();
        });

        // Registered here rather than in composer's provider list so it always
        // boots *after* Nova's own provider. Container rebindings and Nova
        // config edits both depend on winning the last write, and provider
        // order is the only thing that decides that.
        $this->app->register(ToolServiceProvider::class);
    }

    /**
     * Publish the pre-authentication bundle to `public/`.
     *
     * The in-SPA bundle is served by Nova through `Nova::script()`, but the
     * challenge, step-up and enrollment pages render outside Nova's shell and
     * load their script with a plain `<script src>` — so that file has to exist
     * under `public/vendor/nova-two-factor`. Without this the pages still work
     * (they degrade to a plain form post) but lose the segmented code input,
     * passkey support and the lockout countdown.
     */
    protected function publishAssets(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../dist/js/challenge.js' => public_path('vendor/nova-two-factor/js/challenge.js'),
            __DIR__.'/../dist/css/tool.css' => public_path('vendor/nova-two-factor/css/tool.css'),
        ], ['nova-two-factor-assets', 'laravel-assets']);
    }
}
