<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Notifications;

use Carbon\CarbonInterface;
use Gabrielesbaiz\NovaTwoFactor\Support\PanelUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\HtmlString;

/**
 * "Please set up two-factor authentication."
 *
 * Unlike the code mail, this one carries no credential, so it links: the whole
 * point is to put the user on the page where they can act, and a mail that says
 * "go and find your security settings" is a mail people close.
 *
 * Queued when a queue is configured. A reminder is not on anyone's critical
 * path — nobody is sitting at a login screen waiting for it — and an admin
 * reminding forty people should not wait for forty SMTP round-trips.
 */
class EnrollmentReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly ?CarbonInterface $deadline = null,
        private readonly ?string $note = null,
        // Captured where the send happened: an administrator clicking "send
        // reminder" is looking at the panel, so their host is the panel's. A
        // queued job has no request to ask, and `APP_URL` is the customer site
        // on any install where Nova has a domain of its own.
        private readonly ?string $panelUrl = null,
        // Whether the policy actually blocks. Two different mails: one asks,
        // one warns, and sending the warning where nothing is enforced is a
        // threat the application cannot carry out.
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
        return $this->mandatory
            ? $this->mandatoryMail()
            : $this->encouragedMail();
    }

    /**
     * `required`: there is a date, and something happens on it.
     */
    protected function mandatoryMail(): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('Action needed: set up two-factor on :app', ['app' => $this->appName()]))
            ->greeting(__('Two-factor authentication is required'))
            ->line(__('Your :app account needs a second factor.', ['app' => $this->appName()]))
            ->line(__('A password alone can be guessed, reused or stolen by phishing.'));

        if ($this->deadline !== null) {
            $message->line($this->deadlineBlock());
            $message->line(__('After that date you will not be able to sign in until it is set up.'));
        }

        return $this->finish($message);
    }

    /**
     * `encouraged`: an invitation, and it has to read like one.
     *
     * The mandatory mail's deadline block would be a lie here — nothing happens
     * on any date — and a deadline nobody enforces teaches people that the next
     * one is not real either.
     */
    protected function encouragedMail(): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('Protect your :app account with a second factor', ['app' => $this->appName()]))
            ->greeting(__('Add a second factor'))
            ->line(__('Your administrator suggests adding a second factor to your :app account.', ['app' => $this->appName()]))
            ->line(__('A password alone can be guessed, reused or stolen by phishing.'))
            ->line(__('It is not required, but it is the difference between a stolen password and a stolen account.'));

        return $this->finish($message);
    }

    /**
     * The parts both mails share: the admin's note, the button, the sign-off.
     */
    protected function finish(MailMessage $message): MailMessage
    {
        $message->line(__('With a second factor, a stolen password is not enough to reach your account.'))
            ->line(__('Setting one up takes about a minute.'));

        // An administrator's note is quoted as theirs. Unquoted it reads as the
        // application speaking, and "we were breached last week" becomes an
        // announcement nobody authorised.
        if ($this->note !== null && trim($this->note) !== '') {
            $message->line(new HtmlString(
                '<p style="margin: 0 0 16px; padding: 12px 16px; border-left: 3px solid #cbd5e1; color: #475569;">'
                .e(__('Note from your administrator: :note', ['note' => trim($this->note)]))
                .'</p>',
            ));
        }

        return $message
            ->action(__('Set it up'), $this->settingsUrl())
            ->line(__('If you have already set this up, you can ignore this message.'));
    }

    /**
     * The deadline, as an object on the page.
     *
     * Inline styles and a table, because that is the subset of HTML that mail
     * clients agree on — Outlook ignores `<style>` blocks and most of flexbox.
     */
    protected function deadlineBlock(): HtmlString
    {
        $date = e($this->deadline?->isoFormat('LL') ?? '');
        $label = e(__('Please set it up by'));

        return new HtmlString(<<<HTML
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin: 8px 0 24px;">
                <tr>
                    <td style="padding: 14px 18px; border: 1px solid #fbbf24; border-radius: 8px; background-color: #fffbeb;">
                        <span style="display: block; font-size: 12px; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; color: #b45309;">{$label}</span>
                        <span style="display: block; margin-top: 2px; font-size: 18px; font-weight: 700; color: #7c2d12;">{$date}</span>
                    </td>
                </tr>
            </table>
            HTML);
    }

    /**
     * Nova's own user-security page, which is where this package's management
     * card renders.
     *
     * The captured URL wins: `url()` builds from `APP_URL`, and on any install
     * where Nova has a domain of its own that is the *customer* site — so the
     * link in an admin's reminder pointed at a host where the recipient has no
     * account at all.
     */
    protected function settingsUrl(): string
    {
        return $this->panelUrl ?? PanelUrl::userSecurity();
    }

    protected function appName(): string
    {
        return (string) (Config::get('nova.name') ?: Config::get('app.name', 'Laravel'));
    }
}
