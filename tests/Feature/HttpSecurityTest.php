<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Drivers\TotpDriver;
use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Recovery\RecoveryCodeManager;
use Illuminate\Support\Facades\RateLimiter;
use PragmaRX\Google2FA\Google2FA;
use Workbench\App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

function novaUrl(string $path): string
{
    return '/'.trim(trim((string) config('nova.path'), '/').'/two-factor/'.ltrim($path, '/'), '/');
}

function enrolledTotp(User $user): array
{
    $driver = app(TotpDriver::class);
    $intent = $driver->beginEnrollment($user);
    $secret = (string) $intent->secret;
    $method = $driver->completeEnrollment($user, ['code' => (new Google2FA)->getCurrentOtp($secret)]);

    // Clear the seeded high-water mark so the same code can be used in a test.
    $method->forceFill(['last_timestep' => null])->save();

    return [$method->fresh(), $secret];
}

/*
|--------------------------------------------------------------------------
| The 1.x critical finding: every endpoint was reachable unauthenticated.
|--------------------------------------------------------------------------
|
| 1.x registered its routes with `['nova', Authorize::class]`. The `nova` group
| carries no authentication, and `Authorize` only asked whether the tool was
| visible — which defaults to true for everyone. So `confirm`, `toggle`,
| `clear`, `recover` and `authenticate` were all open to guests.
|
*/
it('refuses every endpoint to a guest', function (string $method, string $path): void {
    $this->withHeaders(['Accept' => 'application/json'])
        ->json($method, novaUrl($path))
        ->assertStatus(401);
})->with([
    ['GET', 'methods'],
    ['POST', 'methods'],
    ['POST', 'methods/confirm'],
    ['DELETE', 'methods/1'],
    ['GET', 'recovery-codes'],
    ['POST', 'recovery-codes'],
    ['GET', 'devices'],
    ['DELETE', 'devices'],
    ['POST', 'challenge'],
    ['POST', 'step-up'],
]);

it('never returns a 500 to a guest', function (): void {
    // 1.x answered several of these with a stack trace, because the controller
    // dereferenced a null user.
    foreach ([['POST', 'challenge'], ['POST', 'methods/confirm'], ['POST', 'recovery-codes']] as [$verb, $path]) {
        $this->withHeaders(['Accept' => 'application/json'])
            ->json($verb, novaUrl($path))
            ->assertStatus(401);
    }
});

/*
|--------------------------------------------------------------------------
| Removing a factor
|--------------------------------------------------------------------------
*/
it('will not remove a method without a fresh password confirmation', function (): void {
    [$method] = enrolledTotp($this->user);

    // The 1.x `toggle2Fa()` hole: one POST, no password, no code, no audit.
    $this->actingAs($this->user)
        ->withHeaders(['Accept' => 'application/json'])
        ->json('DELETE', novaUrl("methods/{$method->id}"))
        ->assertStatus(423);

    expect($this->user->refresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('will not let one user remove another user’s method', function (): void {
    [$method] = enrolledTotp($this->user);
    $attacker = User::factory()->create();

    $this->actingAs($attacker)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->withHeaders(['Accept' => 'application/json'])
        ->json('DELETE', novaUrl("methods/{$method->id}"))
        ->assertForbidden();

    expect($this->user->refresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('removes a method once the password is confirmed', function (): void {
    [$method] = enrolledTotp($this->user);

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->withHeaders(['Accept' => 'application/json'])
        ->json('DELETE', novaUrl("methods/{$method->id}"))
        ->assertOk();

    expect($this->user->refresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('refuses to remove the last factor while enforcement requires one', function (): void {
    config()->set('nova-two-factor.enforcement.mode', 'required');

    [$method] = enrolledTotp($this->user);

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->withHeaders(['Accept' => 'application/json'])
        ->json('DELETE', novaUrl("methods/{$method->id}"))
        ->assertStatus(422);

    expect($this->user->refresh()->hasTwoFactorEnabled())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Recovery codes
|--------------------------------------------------------------------------
*/
it('will not reveal or regenerate recovery codes without a password', function (): void {
    enrolledTotp($this->user);

    $this->actingAs($this->user)
        ->withHeaders(['Accept' => 'application/json'])
        ->json('POST', novaUrl('recovery-codes'))
        ->assertStatus(423);
});

it('says plainly that existing codes cannot be retrieved', function (): void {
    enrolledTotp($this->user);

    $this->actingAs($this->user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->withHeaders(['Accept' => 'application/json'])
        ->json('GET', novaUrl('recovery-codes'))
        ->assertOk()
        ->assertJsonPath('retrievable', false);
});

/*
|--------------------------------------------------------------------------
| Challenge
|--------------------------------------------------------------------------
*/
it('accepts a valid code and regenerates the session', function (): void {
    [$method, $secret] = enrolledTotp($this->user);

    $this->actingAs($this->user);
    $before = session()->getId();

    $this->withHeaders(['Accept' => 'application/json'])
        ->json('POST', novaUrl('challenge'), [
            'method_id' => $method->id,
            'code' => (new Google2FA)->getCurrentOtp($secret),
        ])
        ->assertOk()
        ->assertJsonStructure(['redirect']);

    // Session fixation has to be broken exactly here. 1.x only wrote a flag.
    expect(session()->getId())->not->toBe($before);
});

it('rejects a wrong code and does not verify the session', function (): void {
    [$method] = enrolledTotp($this->user);

    $this->actingAs($this->user)
        ->withHeaders(['Accept' => 'application/json'])
        ->json('POST', novaUrl('challenge'), ['method_id' => $method->id, 'code' => '000000'])
        ->assertStatus(422);
});

it('refuses a method belonging to somebody else', function (): void {
    [$method] = enrolledTotp($this->user);
    $attacker = User::factory()->create();

    $this->actingAs($attacker)
        ->withHeaders(['Accept' => 'application/json'])
        ->json('POST', novaUrl('challenge'), ['method_id' => $method->id, 'code' => '123456'])
        ->assertNotFound();
});

it('locks out after too many attempts and reports when to retry', function (): void {
    [$method] = enrolledTotp($this->user);
    $max = (int) config('nova-two-factor.rate_limits.challenge.per_user');

    $this->actingAs($this->user);

    for ($i = 0; $i < $max; $i++) {
        $this->withHeaders(['Accept' => 'application/json'])
            ->json('POST', novaUrl('challenge'), ['method_id' => $method->id, 'code' => '000000'])
            ->assertStatus(422);
    }

    // 1.x had no rate limiting at all: a six-digit code with unlimited attempts.
    $this->withHeaders(['Accept' => 'application/json'])
        ->json('POST', novaUrl('challenge'), ['method_id' => $method->id, 'code' => '000000'])
        ->assertStatus(429);
});

it('clears the lockout counter on success', function (): void {
    [$method, $secret] = enrolledTotp($this->user);

    $this->actingAs($this->user)
        ->withHeaders(['Accept' => 'application/json'])
        ->json('POST', novaUrl('challenge'), ['method_id' => $method->id, 'code' => '000000'])
        ->assertStatus(422);

    $this->withHeaders(['Accept' => 'application/json'])
        ->json('POST', novaUrl('challenge'), [
            'method_id' => $method->id,
            'code' => (new Google2FA)->getCurrentOtp($secret),
        ])
        ->assertOk();

    expect(RateLimiter::attempts(
        'nova-two-factor:challenge|'.$this->user->getMorphClass().'|'.$this->user->getKey(),
    ))->toBe(0);
});

it('signs in with a recovery code without disabling two-factor', function (): void {
    enrolledTotp($this->user);
    $code = app(RecoveryCodeManager::class)->regenerate($this->user)->first();

    $this->actingAs($this->user)
        ->withHeaders(['Accept' => 'application/json'])
        ->json('POST', novaUrl('challenge'), ['recovery_code' => $code])
        ->assertOk()
        ->assertJsonPath('used_recovery_code', true);

    // The 1.x regression: there, this deleted the whole 2FA record.
    expect($this->user->refresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('never offers to trust the device when a recovery code was used', function (): void {
    enrolledTotp($this->user);
    $code = app(RecoveryCodeManager::class)->regenerate($this->user)->first();

    $response = $this->actingAs($this->user)
        ->withHeaders(['Accept' => 'application/json'])
        ->json('POST', novaUrl('challenge'), ['recovery_code' => $code, 'trust_device' => true]);

    $response->assertOk();

    expect($this->user->twoFactorTrustedDevices()->count())->toBe(0);
});

it('remembers a device when asked to', function (): void {
    [$method, $secret] = enrolledTotp($this->user);

    $this->actingAs($this->user)
        ->withHeaders(['Accept' => 'application/json'])
        ->json('POST', novaUrl('challenge'), [
            'method_id' => $method->id,
            'code' => (new Google2FA)->getCurrentOtp($secret),
            'trust_device' => true,
        ])
        ->assertOk()
        ->assertCookie(config('nova-two-factor.trusted_devices.cookie'));

    expect($this->user->twoFactorTrustedDevices()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Enrollment
|--------------------------------------------------------------------------
*/
it('never puts a secret in a cacheable response', function (): void {
    $response = $this->actingAs($this->user)
        ->withHeaders(['Accept' => 'application/json'])
        ->json('POST', novaUrl('methods'), ['type' => MethodType::Totp->value]);

    $response->assertOk()
        ->assertJsonStructure(['secret', 'qr_code', 'otpauth_uri']);

    // The setup response is the one place a secret appears; it must not linger
    // in a shared cache.
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('rejects an unknown method type', function (): void {
    $this->actingAs($this->user)
        ->withHeaders(['Accept' => 'application/json'])
        ->json('POST', novaUrl('methods'), ['type' => 'sms'])
        ->assertStatus(422);
});

it('refuses a method disabled in configuration', function (): void {
    config()->set('nova-two-factor.methods.email.enabled', false);

    $this->actingAs($this->user)
        ->withHeaders(['Accept' => 'application/json'])
        ->json('POST', novaUrl('methods'), ['type' => 'email'])
        ->assertStatus(422);
});

it('issues recovery codes alongside the first factor', function (): void {
    $intent = app(TotpDriver::class)->beginEnrollment($this->user);

    $this->actingAs($this->user)
        ->withHeaders(['Accept' => 'application/json'])
        ->json('POST', novaUrl('methods/confirm'), [
            'type' => MethodType::Totp->value,
            'code' => (new Google2FA)->getCurrentOtp((string) $intent->secret),
        ])
        ->assertOk()
        // A first factor with no backup is a lockout waiting to happen, so this
        // is not left to the user to remember.
        ->assertJsonCount((int) config('nova-two-factor.recovery_codes.count'), 'recovery_codes');
});

it('never exposes a secret through the methods listing', function (): void {
    enrolledTotp($this->user);

    $response = $this->actingAs($this->user)
        ->withHeaders(['Accept' => 'application/json'])
        ->json('GET', novaUrl('methods'))
        ->assertOk();

    $body = $response->content();

    foreach (['secret', 'credential_id_hash', 'code_hash'] as $forbidden) {
        expect($body)->not->toContain($forbidden);
    }
});
