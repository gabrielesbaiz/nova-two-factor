<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor;

use Gabrielesbaiz\NovaTwoFactor\Http\Middleware\RequireFreshTwoFactor;
use Gabrielesbaiz\NovaTwoFactor\Http\Middleware\RequireTwoFactor;
use Gabrielesbaiz\NovaTwoFactor\Http\Middleware\RequireTwoFactorEnrollment;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

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
     * Nova is a dev-only dependency of this package: a host app always has it,
     * but the test suite and static analysis must not assume it.
     */
    protected function novaIsInstalled(): bool
    {
        return class_exists(\Laravel\Nova\Nova::class);
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
    }
}
