<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Nova\Actions;

use Gabrielesbaiz\NovaTwoFactor\Support\TwoFactorUser;
use Gabrielesbaiz\NovaTwoFactor\TrustedDevices\TrustedDeviceManager;
use Illuminate\Support\Collection;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Forces every remembered browser for a user back through the challenge.
 * The first thing to reach for when a laptop goes missing.
 */
class RevokeTrustedDevices extends Action
{
    public $name;

    public bool $destructive = true;

    public function __construct()
    {
        $this->name = __('Revoke trusted devices');
    }

    /**
     * @param  Collection<int, \Illuminate\Database\Eloquent\Model>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $devices = app(TrustedDeviceManager::class);
        $revoked = 0;

        foreach ($models as $model) {
            $revoked += $devices->revokeAll(TwoFactorUser::assert($model));
        }

        return ActionResponse::message(
            trans_choice(
                '{0} No trusted devices to revoke.|{1} :count device revoked.|[2,*] :count devices revoked.',
                $revoked,
                ['count' => $revoked],
            ),
        );
    }

    /**
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [];
    }
}
