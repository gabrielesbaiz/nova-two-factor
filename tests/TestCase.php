<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Tests;

use Gabrielesbaiz\NovaTwoFactor\NovaTwoFactorServiceProvider;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Gate;
use Laravel\Fortify\FortifyServiceProvider;
use Laravel\Nova\NovaCoreServiceProvider;
use Orchestra\Testbench\Concerns\WithWorkbench;
use Orchestra\Testbench\TestCase as Orchestra;
use Workbench\App\Providers\WorkbenchServiceProvider;

abstract class TestCase extends Orchestra
{
    use WithWorkbench;

    /**
     * The admin gate, as a configured application defines it.
     *
     * `nova.admin_gate` ships pointing at an ability no fresh application
     * defines, so the admin pages start closed — anybody who wants them open
     * has to say who may see them. The suite is that somebody: without this
     * every test of the compliance, settings and activity surfaces would be
     * asserting against a 403.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Gate::define('nova-two-factor:admin', static fn (): bool => true);
    }

    protected function getPackageProviders($app): array
    {
        return [
            // Nova first, so our provider boots after it — the whole
            // rebinding strategy depends on that order.
            NovaCoreServiceProvider::class,
            FortifyServiceProvider::class,
            NovaTwoFactorServiceProvider::class,

            // Enforces the morph map, so the suite stores morph aliases rather
            // than fully-qualified class names — which is what a real app does,
            // and the only way a renamed model stays resolvable.
            WorkbenchServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        tap($app['config'], function (Repository $config): void {
            $config->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
            $config->set('app.url', 'https://admin.example.test');

            $config->set('database.default', 'testing');
            $config->set('database.connections.testing', [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ]);

            // An array cache and session keep every test hermetic. Note the
            // cache is never load-bearing for replay protection here — that
            // lives in the database on purpose.
            $config->set('cache.default', 'array');
            $config->set('session.driver', 'array');

            $config->set('nova.path', '/nova');
            $config->set('nova.guard', null);
            $config->set('auth.providers.admins', [
                'driver' => 'eloquent',
                'model' => \Workbench\App\Models\Admin::class,
            ]);
            $config->set('auth.guards.admin', [
                'driver' => 'session',
                'provider' => 'admins',
            ]);
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadLaravelMigrations();
        // The workbench includes the package's publishable `.stub` migration,
        // so the suite exercises the exact file consumers publish rather than a
        // second copy that could drift away from it.
        $this->loadMigrationsFrom(__DIR__.'/../workbench/database/migrations');
    }
}
