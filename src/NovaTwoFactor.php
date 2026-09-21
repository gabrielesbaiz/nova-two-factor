<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor;

use Closure;
use Gabrielesbaiz\NovaTwoFactor\Nova\Dashboards\TwoFactorCompliance;
use Gabrielesbaiz\NovaTwoFactor\Nova\Dashboards\TwoFactorSettings;
use Gabrielesbaiz\NovaTwoFactor\Nova\Resources\TwoFactorAuditLog;
use Gabrielesbaiz\NovaTwoFactor\Support\AuditedModels;
use Gabrielesbaiz\NovaTwoFactor\Support\Enforcement;
use Gabrielesbaiz\NovaTwoFactor\Support\Routing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Laravel\Nova\Menu\MenuItem;
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

        // The path segment has to reach the browser: the card builds its own
        // endpoints, and a hardcoded segment silently stops matching the moment
        // `routes.prefix` is changed — the card then spins on a 404 forever
        // rather than reporting anything.
        Nova::provideToScript([
            'novaTwoFactor' => [
                'prefix' => Routing::prefix(),
            ],
        ]);
    }

    /**
     * Only the admin oversight entry needs a menu item — a user's own settings
     * already live under Nova's user menu, where people look for them.
     *
     * Returns null when `nova.menu.show` is off, which is how a host takes the
     * entry out of Nova's automatic tool menu in order to place it themselves
     * with {@see static::menuSection()} or {@see static::menuItem()}.
     */
    public function menu(Request $request): ?MenuSection
    {
        if (! Config::get('nova-two-factor.nova.menu.show', true)) {
            return null;
        }

        if (! Config::get('nova-two-factor.nova.compliance.enabled', true)) {
            return null;
        }

        return static::menuSection();
    }

    /**
     * The compliance entry as a `MenuSection`, for a host building its own menu:
     *
     *     Nova::mainMenu(fn ($request) => [
     *         MenuSection::dashboard(Main::class)->icon('chart-bar'),
     *         NovaTwoFactor::menuSection(),
     *     ]);
     *
     * Carries its own `canSee` — the admin gate travels with the item, so
     * placing it by hand cannot accidentally expose it.
     */
    public static function menuSection(): MenuSection
    {
        // A group once there is more than one page under it. A section with a
        // `path` cannot also be collapsable — Nova throws — so the group gives
        // up its own click target and the children carry the links.
        if (static::hasSettingsPage()) {
            return MenuSection::make(__((string) Config::get('nova-two-factor.nova.menu.label', 'Security')), static::menuItems())
                ->withBadgeIf(...static::overdueBadge())
                ->icon((string) Config::get('nova-two-factor.nova.menu.icon', 'lock-closed'))
                ->collapsable();
        }

        return MenuSection::dashboard(TwoFactorCompliance::class)
            ->withBadgeIf(...static::overdueBadge())
            ->icon((string) Config::get('nova-two-factor.nova.menu.icon', 'lock-closed'));
    }

    /**
     * The same entry as a `MenuItem`, for nesting inside a section the host
     * already has — an "Administration" group, typically.
     */
    public static function menuItem(): MenuItem
    {
        return MenuItem::dashboard(TwoFactorCompliance::class);
    }

    /**
     * Both pages, for a host building its own menu:
     *
     *     MenuSection::make('Administration', [
     *         MenuItem::resource(User::class),
     *         ...NovaTwoFactor::menuItems(),
     *     ])
     *
     * @return array<int, MenuItem>
     */
    public static function menuItems(): array
    {
        // Short names in the menu, where the group already says the subject —
        // and the full "2FA …" title on the page itself, which is read on its
        // own with no group above it. `MenuItem::dashboard()` would take the
        // page title for both, so the names are given here instead.
        $items = [
            static::link(__('Overview'), '/dashboards/'.(new TwoFactorCompliance)->uriKey(), TwoFactorCompliance::class),
        ];

        if (static::hasSettingsPage()) {
            $items[] = static::link(__('Settings'), '/dashboards/'.(new TwoFactorSettings)->uriKey(), TwoFactorSettings::class);
        }

        $items[] = MenuItem::resource(TwoFactorAuditLog::class)->name(__('Activity'));

        return $items;
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

    /**
     * Name a population compliance is measured against.
     *
     *     NovaTwoFactor::make()
     *         ->audit(Admin::class)
     *         ->audit(User::class, fn ($query) => $query->where('active', true))
     *
     * Call it at all and the guard's own model stops being assumed — which is
     * the point on a panel only administrators can reach, where counting every
     * customer row turns an adoption figure into a number that is confidently
     * wrong and comfortably high.
     *
     * The closure form lives here rather than in config because `config:cache`
     * cannot serialise a closure; `nova.compliance.models` takes class names
     * for the same reason.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $model
     * @param  (Closure(\Illuminate\Database\Eloquent\Builder): \Illuminate\Database\Eloquent\Builder)|null  $scope
     */
    public function audit(string $model, ?Closure $scope = null): static
    {
        AuditedModels::register($model, $scope);

        return $this;
    }

    /**
     * A menu entry that carries the destination's own authorization.
     *
     * @param  class-string<TwoFactorCompliance|TwoFactorSettings>  $dashboard
     */
    protected static function link(string $name, string $path, string $dashboard): MenuItem
    {
        return MenuItem::make($name, $path)
            ->canSee(static fn ($request): bool => (new $dashboard)->authorizedToSee($request));
    }

    /**
     * The settings page earns a menu entry only where it can do something.
     *
     * Read-only it is still registered and still reachable — knowing where a
     * value comes from is useful — but a second entry that only ever says "ask
     * your deployment" is a menu item nobody thanks you for.
     */
    protected static function hasSettingsPage(): bool
    {
        return (bool) Config::get('nova-two-factor.settings.editable', false);
    }

    /**
     * Count of users past their grace period, shown on the menu entry.
     *
     * A number here is the point of the entry: compliance you have to click
     * into to discover has slipped is compliance nobody checks.
     *
     * Memoised for the request because Nova asks twice — once for the
     * condition, once for the badge itself — and this is a chunked pass over
     * the user table, not a cheap read.
     *
     * @return array{0: Closure(): string, 1: string, 2: Closure(): bool}
     */
    protected static function overdueBadge(): array
    {
        $count = null;

        $overdue = static function () use (&$count): int {
            if ($count !== null) {
                return $count;
            }

            if (! Config::get('nova-two-factor.nova.menu.badge', true)) {
                return $count = 0;
            }

            return $count = app(Enforcement::class)->overdueCount();
        };

        return [
            static fn (): string => (string) $overdue(),
            'danger',
            static fn (): bool => $overdue() > 0,
        ];
    }
}
