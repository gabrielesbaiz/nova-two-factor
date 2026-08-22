<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor;

use Closure;
use Gabrielesbaiz\NovaTwoFactor\Support\Enforcement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Laravel\Nova\Menu\MenuSection;
use Laravel\Nova\Nova;
use Laravel\Nova\Tool;

/**
 * Register in `NovaServiceProvider::tools()`.
 *
 * The tool itself is deliberately thin: the management UI replaces Nova's own
 * security card in place rather than living on a tool page, because a
 * tool-registered Inertia page cannot survive a cold load.
 *
 * Instantiate with `NovaTwoFactor::make()`, inherited from Nova's Makeable trait.
 */
class NovaTwoFactor extends Tool
{
    public function boot(): void
    {
        Nova::script('nova-two-factor', __DIR__.'/../dist/js/tool.js');

        if (file_exists(__DIR__.'/../dist/css/tool.css')) {
            Nova::style('nova-two-factor', __DIR__.'/../dist/css/tool.css');
        }
    }

    /**
     * Only the admin oversight entry needs a menu item — a user's own settings
     * already live under Nova's user menu, where people look for them.
     */
    public function menu(Request $request): ?MenuSection
    {
        if (! Config::get('nova-two-factor.nova.menu.show', true) || ! $this->authorizedToViewCompliance($request)) {
            return null;
        }

        return MenuSection::make(__((string) Config::get('nova-two-factor.nova.menu.label', 'Security')))
            ->path('/two-factor/compliance')
            ->icon((string) Config::get('nova-two-factor.nova.menu.icon', 'lock-closed'));
    }

    /**
     * Restrict enforcement to a subset of users.
     *
     * The highest-precedence hook, above both the configured gate and the mode,
     * so an application can always carve out a service account:
     *
     *     NovaTwoFactor::make()->requireFor(fn ($user) => $user->isStaff())
     *
     * @param  Closure(\Illuminate\Contracts\Auth\Authenticatable): bool  $callback
     */
    public function requireFor(Closure $callback): static
    {
        Enforcement::requireUsing($callback);

        return $this;
    }

    public function enforce(string $mode, ?int $graceDays = null): static
    {
        Config::set('nova-two-factor.enforcement.mode', $mode);

        if ($graceDays !== null) {
            Config::set('nova-two-factor.enforcement.grace_days', $graceDays);
        }

        return $this;
    }

    /**
     * Guard a set of routes behind a fresh second factor.
     *
     * @param  array<int, string>  $patterns  e.g. ['DELETE nova-api/users/*']
     */
    public function protectWithStepUp(string $scope, array $patterns, ?int $ttl = null): static
    {
        $protect = Config::get('nova-two-factor.step_up.protect', []);
        $protect[$scope] = $patterns;

        Config::set('nova-two-factor.step_up.protect', $protect);

        if ($ttl !== null) {
            Config::set('nova-two-factor.step_up.ttl', $ttl);
        }

        return $this;
    }

    public function trustDevicesFor(?int $days): static
    {
        Config::set('nova-two-factor.trusted_devices.enabled', $days !== null);

        if ($days !== null) {
            Config::set('nova-two-factor.trusted_devices.days', $days);
        }

        return $this;
    }

    /**
     * @param  array<int, string>  $types
     */
    public function onlyMethods(array $types): static
    {
        foreach (['totp', 'webauthn', 'email'] as $type) {
            Config::set("nova-two-factor.methods.{$type}.enabled", in_array($type, $types, true));
        }

        return $this;
    }

    /**
     * @param  array<int, string>  $types
     */
    public function withoutMethods(array $types): static
    {
        foreach ($types as $type) {
            Config::set("nova-two-factor.methods.{$type}.enabled", false);
        }

        return $this;
    }

    public function withoutMenu(): static
    {
        Config::set('nova-two-factor.nova.menu.show', false);

        return $this;
    }

    protected function authorizedToViewCompliance(Request $request): bool
    {
        $gate = Config::get('nova-two-factor.nova.admin_gate');

        if (! is_string($gate) || $gate === '') {
            return true;
        }

        $user = Nova::user($request);

        return $user !== null && Gate::forUser($user)->allows($gate);
    }
}
