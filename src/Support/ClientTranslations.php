<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Support;

/**
 * The strings the pre-authentication JavaScript writes into the page.
 *
 * Inside Nova's SPA a bundle can read `Nova.config('translations')`. The
 * challenge, step-up and enforcement screens have no Nova instance — they are
 * plain Blade — so anything the script renders after load (a WebAuthn error, a
 * countdown, a status line) would otherwise be hardcoded English on a fully
 * translated page. That is what shipped: an Italian challenge screen answering
 * a failed passkey with "Your device could not complete the request."
 *
 * Every key here is asserted against the keys the bundle actually asks for, so
 * a new message cannot be added to the JavaScript without landing in the
 * catalogues too.
 */
final class ClientTranslations
{
    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return [
            // support/webauthn.js — describeError()
            'Cancelled, or it timed out. Try again.',
            'This device already has a passkey on your account.',
            'Passkeys need a secure (https) connection.',
            'This browser cannot use passkeys.',
            'Passkeys are not set up for this address. Ask your administrator.',
            'Another sign-in request is still open. Close the other tab, or reload this page.',
            'Your device could not complete the request.',

            // challenge.js — status line and failures
            'You can try again now.',
            'Verifying…',
            'Verified.',
            'Too many attempts.',
            'That did not work. Try again.',
            'Try again in :time',
            'Too many attempts. Try again in :time.',
            'The list comes back by itself when the wait is over.',
            'Check again now',
            'We sent a code to :destination.',
            'A code was already sent to :destination.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        $translations = [];

        foreach (self::keys() as $key) {
            $translations[$key] = __($key);
        }

        return $translations;
    }
}
