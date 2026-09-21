<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Nova\Dashboards;

use Gabrielesbaiz\NovaTwoFactor\Nova\Cards\TwoFactorComplianceOverview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Laravel\Nova\Dashboard;
use Laravel\Nova\Nova;

/**
 * Admin compliance: who is covered, by what, and what is failing.
 *
 * A Nova dashboard rather than a tool page, and that is a correctness choice,
 * not a convenience one: a tool-registered Inertia page never resolves on a
 * cold load, so a bookmarked or refreshed compliance URL would spin forever.
 * Dashboards are rendered by Nova's own page component, which does cold-load;
 * the cards are ordinary Vue components resolved by name at render time.
 */
class TwoFactorCompliance extends Dashboard
{
    /**
     * Stable across locales. `uriKey()` would otherwise derive from the class
     * name, which is fine — but it is also the URL a host puts in their own
     * menu, so it is stated rather than inferred.
     */
    public function uriKey(): string
    {
        return 'two-factor-compliance';
    }

    public function label(): string
    {
        return __('2FA overview');
    }

    public function name(): string
    {
        return $this->label();
    }

    /**
     * @return array<int, \Laravel\Nova\Card>
     */
    public function cards(): array
    {
        // One card. The three partition metrics that used to sit here are now
        // inside it — as the coverage strip, the method bar and the failure
        // sparkline — and keeping both would have shown every figure twice, in
        // two visual languages, with two cache lifetimes to disagree across.
        //
        // The metric classes still ship: a host can put any of them on its own
        // resource, which is what the README documents them for.
        return [
            new TwoFactorComplianceOverview,
        ];
    }

    /**
     * The configured admin gate, applied here as well as on the resources and
     * the reset action — a dashboard that merely hides its menu item is still
     * reachable by typing its URL.
     */
    public function authorizedToSee(Request $request): bool
    {
        $gate = Config::get('nova-two-factor.nova.admin_gate');

        if (! is_string($gate) || $gate === '') {
            return true;
        }

        $user = Nova::user($request);

        return $user !== null && Gate::forUser($user)->allows($gate);
    }
}
