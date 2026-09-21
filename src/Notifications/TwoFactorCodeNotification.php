<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Notifications;

use Gabrielesbaiz\NovaTwoFactor\Enums\ChallengePurpose;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\HtmlString;

class TwoFactorCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $code,
        private readonly ChallengePurpose $purpose,
        private readonly int $ttlSeconds,
    ) {
        // `ShouldQueue` is unconditional on the class, so without this the mail
        // queues no matter what `methods.email.queue` says — and a stopped
        // worker becomes a login screen that waits forever for a code nobody
        // is sending. Synchronous is the safe default for a factor that stands
        // between a user and their account; queueing is opt-in.
        if (! Config::get('nova-two-factor.methods.email.queue', false)) {
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
            ->subject(__('Your :app verification code', ['app' => $this->appName()]))
            ->greeting(__('Verification code'))
            ->line(__('Use this code to :action:', ['action' => $this->action()]))
            // The code goes in the body as text, never behind a link. A
            // clickable button in a second-factor email is a ready-made
            // phishing target, and it trains people to click one.
            ->line($this->codeBlock())
            ->line($this->expiry())
            ->line($this->warningBlock());

        return $message->withSymfonyMessage(function ($mail): void {
            // Keep autoresponders and mailing-list software from replying to, or
            // archiving, a message that contains a live credential.
            $headers = $mail->getHeaders();
            $headers->addTextHeader('Auto-Submitted', 'auto-generated');
            $headers->addTextHeader('X-Auto-Response-Suppress', 'All');
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        // Deliberately omits the code: database notifications are readable by
        // anyone with support access to the account.
        return [
            'purpose' => $this->purpose->value,
            'expires_in' => $this->ttlSeconds,
        ];
    }

    /**
     * How long the code lives, in the units it actually lives in.
     *
     * Driven by `methods.email.ttl`, so an application that shortens it does
     * not end up mailing "expires in 5 minutes" for a code good for 90 seconds
     * — and pluralised, because at 60 seconds the old line read "1 minutes".
     */
    protected function expiry(): string
    {
        if ($this->ttlSeconds < 60) {
            return trans_choice(
                '{1} It expires in a second and can only be used once.|[2,*] It expires in :count seconds and can only be used once.',
                $this->ttlSeconds,
                ['count' => $this->ttlSeconds],
            );
        }

        $minutes = (int) round($this->ttlSeconds / 60);

        return trans_choice(
            '{1} It expires in a minute and can only be used once.|[2,*] It expires in :count minutes and can only be used once.',
            $minutes,
            ['count' => $minutes],
        );
    }

    /**
     * The code as its own object on the page, not a bold word in a sentence.
     *
     * Six digits buried mid-paragraph have to be found before they can be read,
     * and they are the only reason the mail exists. Letter-spacing rather than
     * inserted spaces: the digits stay one selectable token, so copy-paste
     * hands the field exactly what it expects.
     *
     * Inline styles and a table, because that is the subset of HTML mail
     * clients agree on — Outlook ignores `<style>` blocks and most of flexbox.
     */
    protected function codeBlock(): HtmlString
    {
        $code = e($this->code);

        return new HtmlString(<<<HTML
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin: 24px 0;">
                <tr>
                    <td align="center">
                        <div style="display: inline-block; padding: 18px 28px; border: 1px solid #e2e8f0; border-radius: 10px; background-color: #f8fafc;">
                            <span style="font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; font-size: 34px; font-weight: 700; letter-spacing: 0.32em; text-indent: 0.32em; color: #0f172a; line-height: 1.1;">{$code}</span>
                        </div>
                    </td>
                </tr>
            </table>
            HTML);
    }

    /**
     * The "you did not ask for this" line, set apart from the instructions.
     *
     * It is addressed to a different reader than the rest of the mail — someone
     * who did *not* start this — and it asks for action on a different system,
     * their password. Set in a quieter colour with air around it rather than
     * behind a rule: a horizontal line in a short mail reads as a footer, which
     * is exactly where this paragraph must not look like it belongs. The space
     * below also keeps it off the salutation.
     */
    protected function warningBlock(): HtmlString
    {
        $warning = e(__('If you did not request this, someone may have your password. Change it, and tell your administrator.'));

        return new HtmlString(<<<HTML
            <table role="presentation" class="n2f-mail-warning" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin: 26px 0 30px;">
                <tr>
                    <td>
                        <span style="font-size: 14px; line-height: 1.5; color: #64748b;">{$warning}</span>
                    </td>
                </tr>
            </table>
            HTML);
    }

    protected function action(): string
    {
        return match ($this->purpose) {
            ChallengePurpose::Login => __('finish signing in'),
            ChallengePurpose::StepUp => __('confirm a sensitive action'),
            ChallengePurpose::Enrollment => __('confirm your email address'),
        };
    }

    protected function appName(): string
    {
        return (string) (Config::get('app.name') ?: 'Laravel Nova');
    }
}
