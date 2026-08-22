<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Nova\Actions;

use Gabrielesbaiz\NovaTwoFactor\Actions\ResetTwoFactor;
use Gabrielesbaiz\NovaTwoFactor\Support\TwoFactorUser;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;

/**
 * Clears every factor for a user, so they can enrol again.
 *
 * Destructive and cross-account, so it demands a written reason and records who
 * performed it. An unexplained reset of somebody else's second factor is
 * indistinguishable from an attack, and the audit row is what tells them apart
 * afterwards.
 */
class ResetTwoFactorAuthentication extends Action
{
    use Queueable;

    public $name;

    public bool $destructive = true;

    public $confirmText;

    public $confirmButtonText;

    public function __construct()
    {
        $this->name = __('Reset two-factor authentication');
        $this->confirmText = __('This removes every two-factor method, recovery code and trusted device for the selected users. They will be asked to set it up again.');
        $this->confirmButtonText = __('Reset');
    }

    public function authorizedToSee(\Illuminate\Http\Request $request): bool
    {
        $gate = Config::get('nova-two-factor.nova.admin_gate');

        if (! is_string($gate) || $gate === '') {
            return true;
        }

        $user = Nova::user($request);

        return $user !== null && Gate::forUser($user)->allows($gate);
    }

    /**
     * @param  Collection<int, \Illuminate\Database\Eloquent\Model>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $reset = app(ResetTwoFactor::class);
        $actor = Nova::user(request());

        $reason = (string) $fields->get('reason');

        foreach ($models as $model) {
            $reset(TwoFactorUser::assert($model), $reason, $actor);
        }

        return ActionResponse::message(
            trans_choice(
                '{1} Two-factor authentication reset for :count user.|[2,*] Two-factor authentication reset for :count users.',
                $models->count(),
                ['count' => $models->count()],
            ),
        );
    }

    /**
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            Textarea::make(__('Reason'), 'reason')
                ->rules('required', 'string', 'min:5', 'max:500')
                ->help(__('Recorded in the audit log against your account.')),
        ];
    }
}
