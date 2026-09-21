<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Nova\Dashboards;

use Gabrielesbaiz\NovaTwoFactor\Nova\Cards\TwoFactorSettingsPanel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Laravel\Nova\Dashboard;
use Laravel\Nova\Nova;

/**
 * Policy, changeable without a deploy.
 *
 * Separate from the admin dashboard rather than a tab on it, because they are
 * different jobs with different risk: the admin page is a morning check that
 * can be handed to a colleague, while this one changes the rules protecting
 * every account and sits behind a password.
 */
class TwoFactorSettings extends Dashboard
{
    public function uriKey(): string
    {
        return 'two-factor-settings';
    }

    public function label(): string
    {
        return __('2FA settings');
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
        return [new TwoFactorSettingsPanel];
    }

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
