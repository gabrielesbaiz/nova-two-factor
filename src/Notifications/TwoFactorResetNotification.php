<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Notifications;

use Gabrielesbaiz\NovaTwoFactor\Support\PanelUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\HtmlString;

/**
 * "An administrator reset your two-factor authentication."
 *
 * Optional, and off by default — a reset is often part of a support call the
 * user is already on, and a mail arriving mid-conversation is noise. Where it
 * is sent, it does two jobs at once, and the second matters more:
 *
 *   1. tells the user what to do now, because their methods are gone and the
 *      next sign-in will not ask for a code they no longer have;
 *   2. tells them it happened at all — which is how somebody notices a reset
 *      they did not ask for. A silent factor removal is indistinguishable from
 *      an attacker with admin access.
 *
 * It deliberately carries no reason text. The reason an administrator writes is
 * for the audit log and may name other people or an incident; what the user
 * needs is the fact and the next step.
 */
class TwoFactorResetNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly ?string $panelUrl = null,
        private readonly bool $mandatory = false,
    ) {
        if (! Config::get('nova-two-factor.enforcement.queue_reminders', true)) {
            $this->onConnection('sync');
        }

        // The locale of the request that sent it, carried onto the queue.
        // A queued notification is rendered by a worker, and a worker has no
        // request — so it falls back to `app.locale`, which on an application
        // that picks the language per request is the wrong one for everybody.
        $this->locale(App::getLocale());
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('Your two-factor authentication was reset on :app', ['app' => $this->appName()]))
            ->greeting(__('Your two-factor authentication was reset'))
            ->line(__('An administrator has removed the two-factor methods on your :app account.', ['app' => $this->appName()]))
            ->line(__('Your recovery codes and trusted devices were removed with them, and your open sessions were signed out.'));

        $message->line($this->whatNowBlock());

        if ($this->mandatory) {
            $message->line(__('Your account requires a second factor, so set one up at your next sign-in.'));
        }

        return $message
            ->action(__('Set it up again'), $this->settingsUrl())
            // The line that makes this mail worth sending: a reset nobody
            // recognises is the only signal a user gets that somebody with
            // admin access is doing things to their account.
            ->line(__('If you did not ask for this, contact your administrator straight away.'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['mandatory' => $this->mandatory];
    }

    /**
     * What happens next, as steps rather than a paragraph.
     *
     * Inline styles, because that is the subset of HTML mail clients agree on.
     */
    protected function whatNowBlock(): HtmlString
    {
        $title = e(__('What to do now'));
        $first = e(__('Sign in with your password as usual.'));
        $second = e(__('Add a method again from your security settings.'));
        $third = e(__('Save the new recovery codes somewhere safe.'));

        return new HtmlString(<<<HTML
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin: 8px 0 24px;">
                <tr>
                    <td style="padding: 14px 18px; border: 1px solid #e2e8f0; border-radius: 8px; background-color: #f8fafc;">
                        <span style="display: block; font-size: 12px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: #475569;">{$title}</span>
                        <span style="display: block; margin-top: 6px; color: #0f172a;">1. {$first}</span>
                        <span style="display: block; margin-top: 2px; color: #0f172a;">2. {$second}</span>
                        <span style="display: block; margin-top: 2px; color: #0f172a;">3. {$third}</span>
                    </td>
                </tr>
            </table>
            HTML);
    }

    protected function settingsUrl(): string
    {
        return $this->panelUrl ?? PanelUrl::userSecurity();
    }

    protected function appName(): string
    {
        return (string) (Config::get('nova.name') ?: Config::get('app.name', 'Laravel'));
    }
}
