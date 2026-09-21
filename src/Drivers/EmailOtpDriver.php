<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Drivers;

use Gabrielesbaiz\NovaTwoFactor\Contracts\TwoFactorMethodDriver;
use Gabrielesbaiz\NovaTwoFactor\Enums\ChallengePurpose;
use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Events\OtpSent;
use Gabrielesbaiz\NovaTwoFactor\Exceptions\InvalidCodeException;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Gabrielesbaiz\NovaTwoFactor\Notifications\TwoFactorCodeNotification;
use Gabrielesbaiz\NovaTwoFactor\Otp\OtpCodeManager;
use Gabrielesbaiz\NovaTwoFactor\Results\ChallengeContext;
use Gabrielesbaiz\NovaTwoFactor\Results\EnrollmentIntent;
use Gabrielesbaiz\NovaTwoFactor\Results\VerificationResult;
use Gabrielesbaiz\NovaTwoFactor\Support\MorphOwner;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;

/**
 * Email one-time codes.
 *
 * The weakest factor the package ships, and labelled as such in the UI rather
 * than presented as equivalent to the others.
 */
class EmailOtpDriver implements TwoFactorMethodDriver
{
    public function __construct(private readonly OtpCodeManager $codes) {}

    public function type(): MethodType
    {
        return MethodType::Email;
    }

    public function isAvailable(): bool
    {
        return (bool) Config::get('nova-two-factor.methods.email.enabled', true);
    }

    /**
     * Create the method unconfirmed and send a code to the destination.
     *
     * Confirming the destination before the method counts is the whole point:
     * without it, "add email 2FA pointing at attacker@example.com" is a
     * complete account-takeover primitive.
     */
    public function beginEnrollment(Authenticatable $user, array $input = []): EnrollmentIntent
    {
        $destination = $this->resolveDestination($user, $input);

        $method = $this->pendingMethod($user, $destination);

        // Issuing a code invalidates the last one, so minting on every entry to
        // the screen turns "click Email code again" into "the code you were
        // sent no longer works". Reuse what is already live; only a deliberate
        // resend replaces it.
        $live = $this->codes->liveChallenge($method);
        $resending = (bool) ($input['resend'] ?? false);

        if ($live !== null && ! $resending) {
            return $this->intentFor($method, sent: false, live: $live);
        }

        if ($live !== null && ! $this->codes->canResend($method)) {
            return $this->intentFor($method, sent: false, live: $live);
        }

        $this->send($user, $method, ChallengePurpose::Enrollment);

        return $this->intentFor($method, sent: true, live: $this->codes->liveChallenge($method));
    }

    public function completeEnrollment(Authenticatable $user, array $input): TwoFactorMethod
    {
        $method = $this->pendingMethodOrFail($user, $input);

        $result = $this->codes->verify($method, (string) ($input['code'] ?? ''));

        if (! $result->passed) {
            throw InvalidCodeException::because((string) $result->failure);
        }

        $method->forceFill([
            'name' => $this->resolveName($input),
            'confirmed_at' => now(),
        ])->save();

        return $method;
    }

    public function beginChallenge(TwoFactorMethod $method, ChallengeContext $context): ?array
    {
        if (! $this->codes->canResend($method)) {
            return [
                'sent' => false,
                'destination_hint' => $method->destination_hint,
                'retry_after' => $this->codes->secondsUntilResend($method),
            ];
        }

        $this->send($context->user, $method, $context->purpose);

        return [
            'sent' => true,
            'destination_hint' => $method->destination_hint,
            'retry_after' => (int) Config::get('nova-two-factor.methods.email.resend_after', 60),
        ];
    }

    public function verify(TwoFactorMethod $method, array $input, ChallengeContext $context): VerificationResult
    {
        if (! $method->isConfirmed()) {
            return VerificationResult::failed(VerificationResult::UNCONFIRMED_METHOD, $method);
        }

        return $this->codes->verify($method, (string) ($input['code'] ?? ''));
    }

    public function suggestName(array $input = []): string
    {
        return $this->type()->label();
    }

    /**
     * Everything the screen needs to explain the state of the inbox.
     */
    protected function intentFor(TwoFactorMethod $method, bool $sent, ?\Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorChallenge $live): EnrollmentIntent
    {
        $ttl = (int) Config::get('nova-two-factor.methods.email.ttl', 300);

        return new EnrollmentIntent(
            type: $this->type()->value,
            destinationHint: $method->destination_hint,
            expiresIn: $live !== null ? max(0, (int) round(now()->diffInSeconds($live->expires_at, false))) : $ttl,
            extra: [
                'method_id' => $method->getKey(),
                // False means "there is already one in your inbox" — the
                // difference decides whether the screen says "we sent" or
                // "we already sent", and the user's next move with it.
                'sent' => $sent,
                'sent_at' => $live?->sent_at?->toIso8601String(),
                'resend_after' => $this->codes->secondsUntilResend($method),
                // Stated in the UI rather than buried: users deserve to know
                // this is the weakest option on the list.
                'strength_warning' => true,
            ],
        );
    }

    protected function send(Authenticatable $user, TwoFactorMethod $method, ChallengePurpose $purpose): void
    {
        [$challenge, $code] = $this->codes->issue($user, $method, $purpose);

        $ttl = (int) Config::get('nova-two-factor.methods.email.ttl', 300);
        $notification = new TwoFactorCodeNotification($code, $purpose, $ttl);

        if (! Config::get('nova-two-factor.methods.email.queue', false)) {
            // Synchronous by default. A queued login code that lands thirty
            // seconds late is indistinguishable from a broken login.
            $notification = $notification->afterCommit();
            Notification::sendNow(
                Notification::route('mail', (string) $method->destination),
                $notification,
            );
        } else {
            Notification::send(
                Notification::route('mail', (string) $method->destination),
                $notification,
            );
        }

        event(new OtpSent($user, $method, [
            'purpose' => $purpose->value,
            'challenge_id' => $challenge->getKey(),
        ]));
    }

    protected function pendingMethod(Authenticatable $user, string $destination): TwoFactorMethod
    {
        /** @var TwoFactorMethod|null $existing */
        $existing = $user->twoFactorMethods()
            ->ofType(MethodType::Email)
            ->pending()
            ->latest('id')
            ->first();

        if ($existing instanceof TwoFactorMethod) {
            $existing->forceFill([
                'destination' => $destination,
                'destination_hint' => $this->hint($destination),
            ])->save();

            return $existing;
        }

        $method = new TwoFactorMethod([
            'type' => MethodType::Email,
            'name' => $this->suggestName(),
            'destination' => $destination,
            'destination_hint' => $this->hint($destination),
        ]);

        $method->authenticatable()->associate(MorphOwner::model($user));
        $method->save();

        return $method;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function pendingMethodOrFail(Authenticatable $user, array $input): TwoFactorMethod
    {
        /** @var TwoFactorMethod|null $method */
        $method = $user->twoFactorMethods()
            ->ofType(MethodType::Email)
            ->pending()
            ->latest('id')
            ->first();

        if (! $method instanceof TwoFactorMethod) {
            throw InvalidCodeException::because(VerificationResult::CEREMONY_EXPIRED);
        }

        return $method;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function resolveDestination(Authenticatable $user, array $input): string
    {
        $supplied = trim((string) ($input['destination'] ?? ''));

        if ($supplied !== '') {
            return $supplied;
        }

        $email = $user->email ?? null;

        if (is_string($email) && $email !== '') {
            return $email;
        }

        throw InvalidCodeException::because('no_destination');
    }

    /**
     * A maskable form safe to render and return.
     *
     * The real destination is encrypted at rest and never leaves the server, so
     * the challenge screen cannot be used to enumerate somebody's address.
     *
     * The mask is a fixed width rather than one star per hidden character: a
     * length-preserving mask still discloses how long the local part is, which
     * is a meaningful hint when you are guessing at somebody's address.
     */
    protected function hint(string $destination): string
    {
        $mask = str_repeat('*', 4);

        if (! str_contains($destination, '@')) {
            return mb_substr($destination, 0, 1).$mask;
        }

        [$local, $domain] = explode('@', $destination, 2);

        return mb_substr($local, 0, 1).$mask.'@'.$domain;
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function resolveName(array $input): string
    {
        $name = trim((string) ($input['name'] ?? ''));

        return $name !== '' ? mb_substr($name, 0, 100) : $this->suggestName($input);
    }
}
