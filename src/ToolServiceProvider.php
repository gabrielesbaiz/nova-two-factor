<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor;

use Gabrielesbaiz\NovaTwoFactor\Auth\SupersedeFortifyTwoFactorChallenge;
use Gabrielesbaiz\NovaTwoFactor\Http\Middleware\RequireFreshTwoFactor;
use Gabrielesbaiz\NovaTwoFactor\Http\Middleware\RequireTwoFactor;
use Gabrielesbaiz\NovaTwoFactor\Http\Middleware\RequireTwoFactorEnrollment;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Nova\Http\Middleware\Authenticate;
use Laravel\Nova\Nova;

/**
 * Everything that has to happen *after* Nova has booted: middleware injection,
 * route registration, and the Fortify container rebindings.
 */
class ToolServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if (! $this->novaIsInstalled()) {
            return;
        }

        $this->registerMiddlewareAlias();
        $this->registerMiddleware();
        $this->registerRoutes();
        $this->supersedeFortifyChallenge();
    }

    /**
     * Lets a host app write `->middleware('nova.2fa.step-up:users.destroy')`
     * on its own routes.
     */
    protected function registerMiddlewareAlias(): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');

        $router->aliasMiddleware('nova.2fa.step-up', RequireFreshTwoFactor::class);
    }

    /**
     * Take Fortify's pre-authentication two-factor divert out of Nova's login
     * pipeline, so this package owns the challenge.
     *
     * Nova installs its own subclass of the action from inside a `ServingNova`
     * listener, which fires per request — long after any provider has booted —
     * so rebinding the abstract here would simply be overwritten. A container
     * extender is used instead: extenders are keyed separately from bindings
     * and survive a later `bind()`/`scoped()` on the same abstract, so this
     * wins regardless of provider order.
     *
     * The concrete class is extended rather than Fortify's contract on
     * purpose. Nova's binding, and Fortify's own contract binding, both resolve
     * through it — but a host that has called
     * `Fortify::redirectUserForTwoFactorAuthenticationUsing()` has replaced the
     * contract deliberately, and that choice is left standing.
     */
    protected function supersedeFortifyChallenge(): void
    {
        if (! Config::get('nova-two-factor.enabled', true)) {
            return;
        }

        if (! Config::get('nova-two-factor.fortify.supersede_challenge', true)) {
            return;
        }

        $this->app->extend(
            RedirectIfTwoFactorAuthenticatable::class,
            fn ($action, $app): SupersedeFortifyTwoFactorChallenge => $app->make(SupersedeFortifyTwoFactorChallenge::class),
        );
    }

    /**
     * Nova is a dev-only dependency of this package: a host app always has it,
     * but the test suite and static analysis must not assume it.
     */
    protected function novaIsInstalled(): bool
    {
        return class_exists(Nova::class);
    }

    /**
     * Append our middleware to *both* Nova middleware groups.
     *
     * `nova.middleware` covers Inertia page routes; `nova.api_middleware`
     * covers every `nova-api/*` resource, action and metric endpoint. 1.x only
     * ever guarded the pages, so enforcement could be sidestepped entirely by
     * talking to the API directly.
     *
     * Both are plain config arrays that Nova reads when it registers routes
     * from `app->booted`, so appending during boot is early enough.
     */
    /**
     * The middleware appended to both Nova groups.
     *
     * Enforcement and step-up land here in a later phase; the list is resolved
     * through a method rather than inlined so that adding them cannot
     * accidentally cover only one of the two groups.
     *
     * @return array<int, class-string>
     */
    protected function twoFactorMiddleware(): array
    {
        return [
            // Order is deliberate. Enrollment first: someone who must enrol and
            // has not cannot be challenged, so sending them to the challenge
            // screen would be a dead end. Then the challenge itself. Step-up
            // last, because it only makes sense once the session is verified.
            RequireTwoFactorEnrollment::class,
            RequireTwoFactor::class,
            RequireFreshTwoFactor::class,
        ];
    }

    protected function registerMiddleware(): void
    {
        if (! Config::get('nova-two-factor.enabled', true)) {
            return;
        }

        $middleware = $this->twoFactorMiddleware();

        if ($middleware === []) {
            return;
        }

        foreach (['nova.middleware', 'nova.api_middleware'] as $group) {
            $existing = Config::get($group, []);

            Config::set($group, array_values(array_unique(array_merge(
                is_array($existing) ? $existing : [],
                $middleware,
            ))));
        }

        $this->pushMiddlewareToNovaGroups($middleware);
    }

    /**
     * Append the middleware to the router groups Nova has already built.
     *
     * Editing `nova.middleware` alone is not enough, and silently so. Nova
     * compiles both config arrays into the `nova` and `nova:api` router groups
     * inside its own core provider's `boot()`. That provider is registered
     * before this one, so by the time this runs the groups exist and no longer
     * consult the config — every guard here would sit in an array nothing
     * reads, leaving Nova completely unguarded while `doctor` reported the
     * configuration as correct.
     *
     * The config write is still made, for a host that boots this package
     * before Nova, and `pushMiddlewareToGroup` is a no-op when the middleware
     * is already present, so the two paths cannot double up.
     *
     * @param  array<int, class-string>  $middleware
     */
    protected function pushMiddlewareToNovaGroups(array $middleware): void
    {
        /** @var Router $router */
        $router = $this->app->make('router');

        $groups = $router->getMiddlewareGroups();

        foreach (['nova', 'nova:api'] as $group) {
            if (! isset($groups[$group])) {
                continue;
            }

            // `nova:api` nests the `nova` group in a standard install, so
            // pushing to both would run every guard twice per API request.
            if ($group === 'nova:api' && in_array('nova', $groups[$group], true)) {
                continue;
            }

            foreach ($middleware as $class) {
                $router->pushMiddlewareToGroup($group, $class);
            }
        }
    }

    /**
     * Register the challenge, step-up and management routes.
     *
     * Under Nova's own path, domain and authenticated middleware, so they
     * inherit its session, guard and CSRF handling. Nothing else loads this
     * file: a host application never sees `routes/nova.php`, and without this
     * the challenge middleware redirects to a URL that 404s.
     *
     * The enforcement except-list already exempts `two-factor/*`, so carrying
     * the `nova` group here cannot trap a user on the screen that releases
     * them.
     */
    protected function registerRoutes(): void
    {
        if (! Config::get('nova-two-factor.enabled', true)) {
            return;
        }

        if ($this->app->routesAreCached()) {
            return;
        }

        Route::domain((string) Config::get('nova.domain') ?: null)
            ->middleware(['nova', Authenticate::class])
            ->prefix(Nova::path())
            ->group(__DIR__.'/../routes/nova.php');
    }
}
