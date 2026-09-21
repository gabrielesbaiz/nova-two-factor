<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Gabrielesbaiz\NovaTwoFactor\NovaTwoFactor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Features;
use Laravel\Nova\Nova;
use Workbench\App\Models\Admin;
use Workbench\App\Models\User;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Factory::guessFactoryNamesUsing(
            static fn (string $model): string => 'Workbench\\Database\\Factories\\'.class_basename($model).'Factory',
        );
    }

    public function boot(): void
    {
        // An explicit morph map, because the package stores the morph alias in
        // four tables. Without one, renaming or namespacing a model silently
        // orphans every enrolled factor.
        \Illuminate\Database\Eloquent\Relations\Relation::enforceMorphMap([
            'user' => User::class,
            'admin' => Admin::class,
        ]);

        // The tool itself, exactly as a host registers it in its
        // NovaServiceProvider. Without it the workbench serves the
        // pre-authentication pages but none of the in-Nova UI — no security
        // card, no dashboards, no settings page — because those arrive through
        // `Nova::script()`, which only runs for a registered tool. A workbench
        // that cannot render the card is one where breaking the card still
        // passes.
        Nova::tools([
            NovaTwoFactor::make(),
        ]);

        // The Fortify features README.md makes a hard requirement of a host, for
        // the reason it gives: Nova only routes `/user-security` when at least
        // one security feature is on, and that page is where the card this
        // package replaces lives. Without them the workbench 404s on the very
        // screen the package exists to render.
        Nova::fortify()->features([
            Features::updatePasswords(),
            // 'confirm' => false: enrollment confirmation belongs to this
            // package, not to Fortify's own endpoint.
            Features::twoFactorAuthentication(['confirm' => false, 'confirmPassword' => false]),
        ]);

        // Nova's own authentication routes, exactly as a host registers them in
        // its NovaServiceProvider. The package's pages link to `nova.logout` —
        // a challenge screen always keeps a way out — and without this the
        // workbench has a route table no real install ever has, so a page that
        // cannot render still passes.
        Nova::routes()
            ->withAuthenticationRoutes()
            ->register();

        // `register()` only records the intent; `bootstrap()` is what puts the
        // routes in the table, and a host gets it from
        // NovaApplicationServiceProvider, which a package workbench does not
        // extend.
        if (! $this->app->routesAreCached()) {
            Nova::routes()->bootstrap($this->app);
        }
    }
}
