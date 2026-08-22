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

    public function label(): string
    {
        return match ($this) {
            self::Totp => 'Authenticator app',
            self::WebAuthn => 'Passkey',
            self::Email => 'Email code',
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
