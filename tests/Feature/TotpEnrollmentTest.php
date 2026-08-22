<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Drivers\TotpDriver;
use Gabrielesbaiz\NovaTwoFactor\Enums\ChallengePurpose;
use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Exceptions\InvalidCodeException;
use Gabrielesbaiz\NovaTwoFactor\Results\VerificationResult;
use Gabrielesbaiz\NovaTwoFactor\Totp\TotpProvider;
use Gabrielesbaiz\NovaTwoFactor\TwoFactorManager;
use Illuminate\Support\Facades\Http;
use PragmaRX\Google2FA\Google2FA;
use Workbench\App\Models\User;

beforeEach(function (): void {
    // Any outbound request at all is a failure here: the QR must be generated
    // locally, and 1.x shipped every secret it made to api.qrserver.com.
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->driver = app(TotpDriver::class);
});

function codeFor(string $secret): string
{
    return (new Google2FA)->getCurrentOtp($secret);
}

it('returns an inline SVG QR code and never a remote URL', function (): void {
    $intent = $this->driver->beginEnrollment($this->user);

    // `xmlns="http://www.w3.org/2000/svg"` is expected and inert; what must
    // never appear is a request to a QR-rendering service.
    expect($intent->qrCodeSvg)->toStartWith('<svg')
        ->and($intent->qrCodeSvg)->not->toContain('qrserver')
        ->and($intent->qrCodeSvg)->not->toContain('chart.googleapis')
        ->and($intent->qrCodeSvg)->not->toContain('src=')
        // An XML declaration would make the markup invalid inline in HTML,
        // which is what made the safe QR option look broken in 1.x.
        ->and($intent->qrCodeSvg)->not->toContain('<?xml');
});

it('offers the setup key in readable groups alongside the QR', function (): void {
    $intent = $this->driver->beginEnrollment($this->user);

    expect($intent->secret)->not->toBeEmpty()
        ->and($intent->secretGroups())->toContain(' ')
        ->and(str_replace(' ', '', (string) $intent->secretGroups()))->toBe($intent->secret)
        ->and($intent->otpauthUri)->toStartWith('otpauth://totp/');
});

it('does not rotate the pending secret when enrollment is reloaded', function (): void {
    // 1.x regenerated on every render of the setup page, silently invalidating
    // the recovery code the user had just written down.
    $first = $this->driver->beginEnrollment($this->user);
    $second = $this->driver->beginEnrollment($this->user);

    expect($second->secret)->toBe($first->secret);
});

it('writes nothing to the database until enrollment is confirmed', function (): void {
    $this->driver->beginEnrollment($this->user);

    expect($this->user->twoFactorMethods()->count())->toBe(0)
        ->and($this->user->hasTwoFactorEnabled())->toBeFalse();
});

it('confirms enrollment with a valid code', function (): void {
    $intent = $this->driver->beginEnrollment($this->user);

    $method = $this->driver->completeEnrollment($this->user, ['code' => codeFor((string) $intent->secret)]);

    expect($method->type)->toBe(MethodType::Totp)
        ->and($method->isConfirmed())->toBeTrue()
        ->and($this->user->refresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('rejects enrollment with a wrong code', function (): void {
    $this->driver->beginEnrollment($this->user);

    expect(fn () => $this->driver->completeEnrollment($this->user, ['code' => '000000']))
        ->toThrow(InvalidCodeException::class);

    expect($this->user->twoFactorMethods()->count())->toBe(0);
});

it('rejects enrollment once the pending secret has expired', function (): void {
    $this->driver->beginEnrollment($this->user);

    $this->travel(config('nova-two-factor.methods.totp.enrollment_ttl') + 60)->seconds();

    expect(fn () => $this->driver->completeEnrollment($this->user, ['code' => '123456']))
        ->toThrow(InvalidCodeException::class);
});

it('refuses to replay the enrollment code at the next challenge', function (): void {
    // The step consumed to enrol is seeded as the replay high-water mark, so the
    // code the user just typed cannot immediately log them in a second time.
    $intent = $this->driver->beginEnrollment($this->user);
    $code = codeFor((string) $intent->secret);

    $method = $this->driver->completeEnrollment($this->user, ['code' => $code]);

    $result = app(TwoFactorManager::class)->verify(
        $method,
        ['code' => $code],
        app(TwoFactorManager::class)->context($this->user, ChallengePurpose::Login),
    );

    expect($result->passed)->toBeFalse()
        ->and($result->failure)->toBe(VerificationResult::REPLAYED);
});

it('accepts a code whose timestep has not been claimed yet', function (): void {
    $intent = $this->driver->beginEnrollment($this->user);
    $secret = (string) $intent->secret;

    $method = $this->driver->completeEnrollment($this->user, ['code' => codeFor($secret)]);

    // google2fa reads the real clock, so `travel()` cannot move it. Rolling the
    // stored high-water mark back is the honest way to reach the accept path:
    // it is precisely the state a later code would produce.
    $method->forceFill(['last_timestep' => null])->save();

    $manager = app(TwoFactorManager::class);
    $result = $manager->verify(
        $method->fresh(),
        ['code' => codeFor($secret)],
        $manager->context($this->user, ChallengePurpose::Login),
    );

    expect($result->passed)->toBeTrue()
        ->and($result->timestep)->not->toBeNull();
});

it('generates a secret with at least 80 bits even if misconfigured', function (): void {
    config()->set('nova-two-factor.methods.totp.secret_bytes', 4);

    expect(strlen(app(TotpProvider::class)->generateSecret()))->toBeGreaterThanOrEqual(16);
});

it('tolerates codes pasted with surrounding text', function (): void {
    $intent = $this->driver->beginEnrollment($this->user);
    $secret = (string) $intent->secret;

    $method = $this->driver->completeEnrollment($this->user, ['code' => codeFor($secret)]);
    $method->forceFill(['last_timestep' => null])->save();

    $manager = app(TwoFactorManager::class);
    $result = $manager->verify(
        $method->fresh(),
        ['code' => 'Your code is '.codeFor($secret)],
        $manager->context($this->user, ChallengePurpose::Login),
    );

    expect($result->passed)->toBeTrue();
});

it('fails rather than erroring on a malformed secret', function (): void {
    $method = $this->user->twoFactorMethods()->create([
        'type' => MethodType::Totp,
        'name' => 'Broken',
        'secret' => 'not-valid-base32!!',
        'confirmed_at' => now(),
    ]);

    $manager = app(TwoFactorManager::class);
    $result = $manager->verify(
        $method,
        ['code' => '123456'],
        $manager->context($this->user, ChallengePurpose::Login),
    );

    expect($result->passed)->toBeFalse();
});

it('refuses to verify an unconfirmed method', function (): void {
    $method = $this->user->twoFactorMethods()->create([
        'type' => MethodType::Totp,
        'name' => 'Pending',
        'secret' => 'JBSWY3DPEHPK3PXP',
    ]);

    $manager = app(TwoFactorManager::class);
    $result = $manager->verify(
        $method,
        ['code' => codeFor('JBSWY3DPEHPK3PXP')],
        $manager->context($this->user, ChallengePurpose::Login),
    );

    expect($result->failure)->toBe(VerificationResult::UNCONFIRMED_METHOD);
});
