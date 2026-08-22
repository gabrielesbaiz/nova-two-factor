<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\WebAuthn;

use Webauthn\Counter\CounterChecker;
use Webauthn\CredentialRecord;

/**
 * Hands the signature-counter decision back to us.
 *
 * The library's default checker throws whenever the new counter is not greater
 * than the stored one — which rejects every synced passkey on the planet, since
 * iCloud Keychain and Google Password Manager both report a permanent zero.
 * Regression is still refused, but by {@see \Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod::claimSignCount()},
 * which exempts the zero-to-zero case and records an audit event on a genuine
 * rollback rather than throwing an opaque exception mid-ceremony.
 */
final class DeferredCounterChecker implements CounterChecker
{
    public function check(CredentialRecord $credentialRecord, int $currentCounter): void
    {
        // Intentionally permissive here; enforced on the model.
    }
}
