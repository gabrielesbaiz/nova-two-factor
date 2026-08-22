<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\WebAuthn;

/**
 * Maps an authenticator's AAGUID to a human name.
 *
 * A trimmed, bundled subset rather than a live FIDO metadata service lookup:
 * naming a passkey is a convenience, and it must not depend on an outbound
 * request at enrollment time. Unknown authenticators simply fall back to a
 * generic label, and the user can rename it inline anyway.
 *
 * @see https://fidoalliance.org/metadata/
 */
class AaguidRegistry
{
    /**
     * @var array<string, string>
     */
    protected const NAMES = [
        'adce0002-35bc-c60a-648b-0b25f1f05503' => 'Chrome on macOS',
        '08987058-cadc-4b81-b6e1-30de50dcbe96' => 'Windows Hello',
        '9ddd1817-af5a-4672-a2b9-3e3dd95000a9' => 'Windows Hello',
        '6028b017-b1d4-4c02-b4b3-afcdafc96bb2' => 'Windows Hello',
        'dd4ec289-e01d-41c9-bb89-70fa845d4bf2' => 'iCloud Keychain',
        'fbfc3007-154e-4ecc-8c0b-6e020557d7bd' => 'iCloud Keychain',
        'ea9b8d66-4d01-1d21-3ce4-b6b48cb575d4' => 'Google Password Manager',
        'b5397666-4885-aa6b-cebf-e52262a439a2' => 'Chromium',
        'cb69481e-8ff7-4039-93ec-0a2729a154a8' => 'YubiKey 5',
        'ee882879-721c-4913-9775-3dfcce97072a' => 'YubiKey 5',
        'fa2b99dc-9e39-4257-8f92-4a30d23c4118' => 'YubiKey 5 NFC',
        '2fc0579f-8113-47ea-b116-bb5a8db9202a' => 'YubiKey 5 NFC',
        'c1f9a0bc-1dd2-404a-b27f-8e29047a43fd' => 'YubiKey 5 NFC FIPS',
        'd8522d9f-575b-4866-88a9-ba99fa02f35b' => 'YubiKey Bio',
        'f8a011f3-8c0a-4d15-8006-17111f9edc7d' => 'Security Key by Yubico',
        'b92c3f9a-c014-4056-887f-140a2501163b' => 'Security Key by Yubico',
        '6d44ba9b-f6ec-2e49-b930-0c8fe920cb73' => 'Security Key NFC',
        '149a2021-8ef6-4133-96b8-81f8d5b7f1f5' => 'Security Key NFC',
        '0076631b-d4a0-427f-5773-0ec71c9e0279' => 'SoloKey',
        '8876631b-d4a0-427f-5773-0ec71c9e0279' => 'SoloKey',
        'd41f5a69-b817-4144-a13c-9ebd6d9254d6' => 'ATKey',
        '3789da91-f943-46bc-95c3-50ea2012f03a' => 'NEOWAVE Winkeo',
        '531126d6-e717-415c-9320-3d9aa6981239' => 'Dashlane',
        'bada5566-a7aa-401f-bd96-45619a55120d' => '1Password',
        'b84e4048-15dc-4dd0-8b7d-65d3d6b3c9e9' => 'YubiKey 5 FIPS',
        'd548826e-79b4-db40-a3d8-11116f7e8349' => 'Bitwarden',
        '891494da-2c90-4d31-a9cd-4eab0aed1309' => 'Sésame',
    ];

    public function name(?string $aaguid): ?string
    {
        if ($aaguid === null || $aaguid === '') {
            return null;
        }

        $normalized = strtolower(trim($aaguid));

        // An all-zero AAGUID means the authenticator declined to identify
        // itself, which every platform passkey does when attestation is `none`.
        if (preg_match('/^0+(-0+)*$/', $normalized) === 1) {
            return null;
        }

        return static::NAMES[$normalized] ?? null;
    }

    public function isKnown(?string $aaguid): bool
    {
        return $this->name($aaguid) !== null;
    }
}
