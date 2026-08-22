<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Notifications;

use Gabrielesbaiz\NovaTwoFactor\Enums\ChallengePurpose;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

class TwoFactorCodeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $code,
        private readonly ChallengePurpose $purpose,
        private readonly int $ttlSeconds,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $minutes = max(1, (int) round($this->ttlSeconds / 60));

        $message = (new MailMessage)
            ->subject(__('Your :app verification code', ['app' => $this->appName()]))
            ->greeting(__('Verification code'))
            ->line(__('Use this code to :action:', ['action' => $this->action()]))
            // The code goes in the body as text, never behind a link. A
            // clickable button in a second-factor email is a ready-made
            // phishing target, and it trains people to click one.
            ->line('**'.$this->code.'**')
            ->line(__('It expires in :count minutes and can only be used once.', ['count' => $minutes]))
            ->line(__('If you did not request this, someone may have your password. Change it, and tell your administrator.'));

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
