<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Support;

/**
 * WebAuthn moves every binary value as base64url. Standard base64 is not
 * interchangeable: `+/` versus `-_` and the stripped padding both matter.
 */
final class Base64Url
{
    public static function encode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    public static function decode(string $encoded): string
    {
        $padded = strtr($encoded, '-_', '+/');
        $remainder = strlen($padded) % 4;

        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode($padded, true) ?: '';
    }
}
