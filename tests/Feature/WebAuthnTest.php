<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Enums\ChallengePurpose;
use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Exceptions\MethodUnavailableException;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Gabrielesbaiz\NovaTwoFactor\Results\VerificationResult;
use Gabrielesbaiz\NovaTwoFactor\TwoFactorManager;
use Gabrielesbaiz\NovaTwoFactor\WebAuthn\AaguidRegistry;
use Gabrielesbaiz\NovaTwoFactor\WebAuthn\CeremonyStore;
use Gabrielesbaiz\NovaTwoFactor\WebAuthn\RelyingParty;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\User;

beforeEach(function (): void {
    config()->set('app.url', 'https://admin.example.test');
    $this->user = User::factory()->create();
});

it('derives the relying party id from app.url, not the request host', function (): void {
    // The Host header is attacker-controlled. An RP id taken from it lets a
    // request through a rogue hostname bind credentials usable on the real one.
    $rp = RelyingParty::resolve();

    expect($rp->id)->toBe('admin.example.test')
        ->and($rp->origins)->toBe(['https://admin.example.test']);
});

it('refuses a relying party id that is not a parent of the app host', function (): void {
    config()->set('nova-two-factor.methods.webauthn.relying_party.id', 'evil.test');

    expect(fn () => RelyingParty::resolve())->toThrow(MethodUnavailableException::class);
});

it('accepts a registrable parent domain as the relying party id', function (): void {
    config()->set('app.url', 'https://nova.admin.example.test');
    config()->set('nova-two-factor.methods.webauthn.relying_party.id', 'example.test');

    expect(RelyingParty::resolve()->id)->toBe('example.test');
});

it('matches origins exactly rather than by prefix', function (): void {
    $rp = RelyingParty::resolve();

    expect($rp->permitsOrigin('https://admin.example.test'))->toBeTrue()
        ->and($rp->permitsOrigin('https://admin.example.test/'))->toBeTrue()
        // The classic fail-open: a substring or prefix test would wave these through.
        ->and($rp->permitsOrigin('https://admin.example.test.evil.test'))->toBeFalse()
        ->and($rp->permitsOrigin('http://admin.example.test'))->toBeFalse()
        ->and($rp->permitsOrigin('https://admin.example.test:8443'))->toBeFalse();
});

it('does not demand a secure context on localhost', function (): void {
    config()->set('app.url', 'http://localhost');

    expect(RelyingParty::resolve()->requiresSecureContext())->toBeFalse();
});

it('offers creation options bound to the relying party', function (): void {
    $intent = app(Gabrielesbaiz\NovaTwoFactor\Drivers\WebAuthnDriver::class)->beginEnrollment($this->user);

    $publicKey = $intent->toArray()['public_key'];

    expect($publicKey['rp']['id'])->toBe('admin.example.test')
        ->and($publicKey['challenge'])->not->toBeEmpty()
        // Discoverable credentials are what make passkey autofill possible on
        // the challenge screen.
        ->and($publicKey['authenticatorSelection']['residentKey'])->toBe('preferred');
});

it('excludes already-enrolled credentials so one authenticator cannot be added twice', function (): void {
    $this->user->twoFactorMethods()->create([
        'type' => MethodType::WebAuthn,
        'name' => 'Existing key',
        'credential_id' => 'ZXhpc3RpbmctY3JlZGVudGlhbA',
        'credential_id_hash' => hash('sha256', 'ZXhpc3RpbmctY3JlZGVudGlhbA'),
        'confirmed_at' => now(),
    ]);

    $intent = app(Gabrielesbaiz\NovaTwoFactor\Drivers\WebAuthnDriver::class)->beginEnrollment($this->user);

    expect($intent->toArray()['public_key']['excludeCredentials'])->toHaveCount(1);
});

it('stores a single-use ceremony challenge', function (): void {
    $store = app(CeremonyStore::class);
    $store->put('challenge-value', ChallengePurpose::Enrollment, 'handle');

    expect($store->pull(ChallengePurpose::Enrollment))->not->toBeNull()
        // Pulled, not read: a challenge that survives its own use is replayable.
        ->and($store->pull(ChallengePurpose::Enrollment))->toBeNull();
});

it('refuses to reuse a registration ceremony as an assertion', function (): void {
    $store = app(CeremonyStore::class);
    $store->put('challenge-value', ChallengePurpose::Enrollment, 'handle');

    expect($store->pull(ChallengePurpose::Login))->toBeNull();
});

it('expires a ceremony challenge', function (): void {
    $store = app(CeremonyStore::class);
    $store->put('challenge-value', ChallengePurpose::Login, 'handle');

    $this->travel(config('nova-two-factor.methods.webauthn.timeout') + 5)->seconds();

    expect($store->pull(ChallengePurpose::Login))->toBeNull();
});

it('names a credential from its aaguid when the model is known', function (): void {
    $registry = app(AaguidRegistry::class);

    expect($registry->name('fa2b99dc-9e39-4257-8f92-4a30d23c4118'))->toBe('YubiKey 5 NFC')
        ->and($registry->name('dd4ec289-e01d-41c9-bb89-70fa845d4bf2'))->toBe('iCloud Keychain');
});

it('treats an all-zero aaguid as anonymous', function (): void {
    // Every platform passkey reports zeros when attestation is `none`, which is
    // the default. That is not an authenticator model, it is the absence of one.
    expect(app(AaguidRegistry::class)->name('00000000-0000-0000-0000-000000000000'))->toBeNull();
});

it('encrypts the stored credential', function (): void {
    $this->user->twoFactorMethods()->create([
        'type' => MethodType::WebAuthn,
        'name' => 'Passkey',
        'credential' => ['publicKey' => 'secret-key-material', 'aaguid' => 'x'],
        'credential_id' => 'abc',
        'credential_id_hash' => hash('sha256', 'abc'),
        'confirmed_at' => now(),
    ]);

    $stored = DB::table(config('nova-two-factor.database.tables.methods'))->value('credential');

    expect($stored)->not->toContain('secret-key-material');
});

it('rejects an assertion with no ceremony in flight', function (): void {
    $method = $this->user->twoFactorMethods()->create([
        'type' => MethodType::WebAuthn,
        'name' => 'Passkey',
        'credential' => ['publicKey' => 'x'],
        'credential_id' => 'abc',
        'credential_id_hash' => hash('sha256', 'abc'),
        'confirmed_at' => now(),
    ]);

    $manager = app(TwoFactorManager::class);
    $result = $manager->verify(
        $method,
        ['credential' => '{}'],
        $manager->context($this->user, ChallengePurpose::Login),
    );

    expect($result->failure)->toBe(VerificationResult::CEREMONY_EXPIRED);
});

it('enforces a unique credential id across the whole table', function (): void {
    $id = 'duplicate-credential';

    $this->user->twoFactorMethods()->create([
        'type' => MethodType::WebAuthn,
        'name' => 'First',
        'credential_id' => $id,
        'credential_id_hash' => hash('sha256', $id),
        'confirmed_at' => now(),
    ]);

    // A credential id must resolve to exactly one account, or usernameless
    // sign-in has no answer to "whose passkey is this?".
    expect(fn () => User::factory()->create()->twoFactorMethods()->create([
        'type' => MethodType::WebAuthn,
        'name' => 'Second',
        'credential_id' => $id,
        'credential_id_hash' => hash('sha256', $id),
        'confirmed_at' => now(),
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

it('requires user verification for a step-up regardless of configuration', function (): void {
    config()->set('nova-two-factor.methods.webauthn.user_verification', 'discouraged');

    $method = $this->user->twoFactorMethods()->create([
        'type' => MethodType::WebAuthn,
        'name' => 'Passkey',
        'credential' => ['publicKey' => 'x'],
        'credential_id' => 'abc',
        'credential_id_hash' => hash('sha256', 'abc'),
        'confirmed_at' => now(),
    ]);

    $manager = app(TwoFactorManager::class);
    $payload = app(Gabrielesbaiz\NovaTwoFactor\Drivers\WebAuthnDriver::class)->beginChallenge(
        $method,
        $manager->context($this->user, ChallengePurpose::StepUp),
    );

    expect($payload['user_verification_required'])->toBeTrue()
        ->and($payload['public_key']['userVerification'])->toBe('required');
});

it('does not require user verification for an ordinary login', function (): void {
    $method = TwoFactorMethod::query()->create([
        'authenticatable_type' => $this->user->getMorphClass(),
        'authenticatable_id' => $this->user->getKey(),
        'type' => MethodType::WebAuthn,
        'name' => 'Passkey',
        'credential' => ['publicKey' => 'x'],
        'credential_id' => 'abc',
        'credential_id_hash' => hash('sha256', 'abc'),
        'confirmed_at' => now(),
    ]);

    $manager = app(TwoFactorManager::class);
    $payload = app(Gabrielesbaiz\NovaTwoFactor\Drivers\WebAuthnDriver::class)->beginChallenge(
        $method,
        $manager->context($this->user, ChallengePurpose::Login),
    );

    expect($payload['user_verification_required'])->toBeFalse();
});
