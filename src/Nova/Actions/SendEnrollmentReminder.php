<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Nova\Actions;

use Gabrielesbaiz\NovaTwoFactor\Events\EnrollmentReminderSent;
use Gabrielesbaiz\NovaTwoFactor\Notifications\EnrollmentReminderNotification;
use Gabrielesbaiz\NovaTwoFactor\Reminders\EnrollmentReminders;
use Gabrielesbaiz\NovaTwoFactor\Support\Enforcement;
use Gabrielesbaiz\NovaTwoFactor\Support\PanelUrl;
use Gabrielesbaiz\NovaTwoFactor\Support\TwoFactorUser;
use Illuminate\Bus\Queueable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Laravel\Nova\Actions\Action;
use Laravel\Nova\Actions\ActionResponse;
use Laravel\Nova\Fields\ActionFields;
use Laravel\Nova\Fields\Textarea;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;

/**
 * Ask selected users to enrol.
 *
 * The humane half of enforcement. `required` mode eventually locks people out
 * of the panel they came to work in, and the first they hear of it is the wall;
 * this is how an administrator gives them the warning and the link beforehand.
 *
 * Deliberately not destructive, and deliberately skips anyone already enrolled:
 * the failure mode of a reminder is not danger, it is being ignored, and the
 * fastest way to get it ignored is to send it to people who have complied.
 */
class SendEnrollmentReminder extends Action
{
    use Queueable;

    public $name;

    public $confirmButtonText;

    public function __construct()
    {
        $this->name = __('Send enrollment reminder');
        $this->confirmButtonText = __('Send reminder');
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

    /**
     * @param  Collection<int, Model>  $models
     */
    public function handle(ActionFields $fields, Collection $models): ActionResponse
    {
        $enforcement = app(Enforcement::class);
        $reminders = app(EnrollmentReminders::class);
        // Nova's helper reads the panel's own guard, which is the right answer
        // inside Nova. `Auth::user()` is the fallback for an action invoked
        // outside a Nova request — otherwise the audit row names nobody, which
        // is the one thing this row exists to avoid.
        $actor = Nova::user(request()) ?? Auth::user();
        $note = trim((string) $fields->get('note')) ?: null;
        $panelUrl = PanelUrl::userSecurity();
        $mandatory = $enforcement->mode()->blocks();

        $sent = 0;
        $skipped = 0;

        foreach ($models as $model) {
            $user = TwoFactorUser::tryFrom($model);

            if ($user === null || $user->confirmedTwoFactorMethods()->isNotEmpty()) {
                $skipped++;

                continue;
            }

            if (! $this->canBeNotified($model)) {
                $skipped++;

                continue;
            }

            // Skipped, not refused: a selection of forty where two were chased
            // yesterday should send the other thirty-eight, and say so.
            if ($reminders->recentlyReminded($model)) {
                $skipped++;

                continue;
            }

            // Through the facade rather than `$model->notify()`: the model is
            // typed as an Eloquent model, and only the Notifiable trait adds
            // that method. `Notification::send()` takes the same object and
            // routes it the same way.
            Notification::send($model, new EnrollmentReminderNotification(
                // Only where the date means something: under `encouraged`
                // `graceEndsAt()` still returns one, and printing it as a
                // deadline promises an enforcement that never comes.
                $mandatory ? $enforcement->graceEndsAt($user) : null,
                $note,
                // Captured here, where a request exists: the mail may be
                // delivered by a queue worker that has no idea which host the
                // panel answers on.
                $panelUrl,
                $mandatory,
            ));

            Event::dispatch(new EnrollmentReminderSent(
                $user,
                context: ['by' => $actor?->getAuthIdentifier(), 'note' => $note],
            ));

            $sent++;
        }

        // Both numbers, always. "Sent 3" over a selection of ten looks like a
        // failure unless the page also says the other seven were already
        // covered — which is the good news, not an error.
        $message = trans_choice(
            '{1} Reminder sent to :count user.|[2,*] Reminders sent to :count users.',
            $sent,
            ['count' => $sent],
        );

        if ($skipped > 0) {
            $message .= ' '.trans_choice(
                '{1} :count user was skipped: already enrolled, or no address to write to.|[2,*] :count users were skipped: already enrolled, or no address to write to.',
                $skipped,
                ['count' => $skipped],
            );
        }

        return $sent === 0 && $skipped > 0
            ? ActionResponse::danger($message)
            : ActionResponse::message($message);
    }

    /**
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            Textarea::make(__('Note'), 'note')
                ->rules('nullable', 'string', 'max:500')
                ->help(__('Optional. Quoted in the mail as coming from you — say why, or when it has to be done by.')),
        ];
    }

    /**
     * A model can only be reminded if something will carry the mail.
     *
     * Checked rather than assumed: `notify()` on a model without the trait is a
     * fatal error mid-loop, which would leave half a selection reminded and no
     * record of which half.
     */
    protected function canBeNotified(Model $model): bool
    {
        return method_exists($model, 'notify')
            && method_exists($model, 'routeNotificationFor')
            && (bool) ($model->getAttribute('email') ?? false);
    }
}
