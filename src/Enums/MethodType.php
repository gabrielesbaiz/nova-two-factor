<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Enums;

enum MethodType: string
{
    case Totp = 'totp';
    case WebAuthn = 'webauthn';
    case Email = 'email';

    /**
     * Ordered strongest first. Drives which method is offered by default at the
     * challenge, and the ordering of the enrollment picker.
     *
     * @return array<int, self>
     */
    public static function byStrength(): array
    {
        return [self::WebAuthn, self::Totp, self::Email];
    }

    /**
     * The factor's name as a user reads it.
     *
     * Translated here rather than at every call site: the label reaches the
     * enforcement screen, the challenge chooser, the security card, the audit
     * log and three notifications, and a literal returned from an enum is a
     * string no catalogue can reach.
     */
    public function label(): string
    {
        return match ($this) {
            self::Totp => __('Authenticator app'),
            self::WebAuthn => __('Passkey'),
            self::Email => __('Email code'),
        };
    }

    /**
     * One line on what the factor actually is.
     */
    public function description(): string
    {
        return match ($this) {
            self::Totp => __('A code from an app on your phone.'),
            self::WebAuthn => __('Fingerprint, face, or a security key.'),
            self::Email => __('A code sent to your inbox.'),
        };
    }

    /**
     * The catch, in the factor's own terms.
     *
     * Kept apart from the description, and on its own line, because it is the
     * sentence that decides the choice: "cannot be phished" and "anyone with
     * your inbox has your second factor" are not decoration. An application
     * that would rather not put security trade-offs in front of end users can
     * turn them off with `ui.show_method_tradeoffs`.
     */
    public function tradeoff(): string
    {
        return match ($this) {
            self::Totp => __('Works offline.'),
            self::WebAuthn => __('Cannot be phished.'),
            self::Email => __('Weakest option — anyone with your inbox has your second factor.'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Totp => 'device-phone-mobile',
            self::WebAuthn => 'finger-print',
            self::Email => 'envelope',
        };
    }

    /**
     * Whether the factor resists phishing. Only WebAuthn does — it is bound to
     * the origin, so a proxied login page cannot replay it. Surfaced in the UI
     * rather than hidden, so users can make an informed choice.
     */
    public function isPhishingResistant(): bool
    {
        return $this === self::WebAuthn;
    }

    /**
     * A code the user types, as opposed to a ceremony the browser performs.
     */
    public function isCodeBased(): bool
    {
        return $this !== self::WebAuthn;
    }
}
