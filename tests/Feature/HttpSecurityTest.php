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

/**
 * A session that has cleared both gates the destructive routes now sit behind:
 * a fresh password, and the second factor itself. The factor matters because
 * these routes can hand out or destroy one, and the attacker they defend
 * against is the one already holding the password.
 *
 * @return array<string, mixed>
 */
function confirmedAndVerified(User $user): array
{
    return [
        'auth.password_confirmed_at' => time(),
        'nova_two_factor.passed_at' => time(),
        'nova_two_factor.user' => $user->getMorphClass().'|'.$user->getAuthIdentifier(),
    ];
}

it('removes a method once the password is confirmed', function (): void {
    [$method] = enrolledTotp($this->user);

    $this->actingAs($this->user)
        ->withSession(confirmedAndVerified($this->user))
        ->withHeaders(['Accept' => 'application/json'])
        ->json('DELETE', novaUrl("methods/{$method->id}"))
        ->assertOk();

    expect($this->user->refresh()->hasTwoFactorEnabled())->toBeFalse();
});

it('refuses to remove the last factor while enforcement requires one', function (): void {
    config()->set('nova-two-factor.enforcement.mode', 'required');

    [$method] = enrolledTotp($this->user);

    $this->actingAs($this->user)
        ->withSession(confirmedAndVerified($this->user))
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
        ->withSession(confirmedAndVerified($this->user))
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

/*
|--------------------------------------------------------------------------
| Throttling speaks the application's language
|--------------------------------------------------------------------------
|
| Laravel's `throttle` middleware aborts with the literal string
| "Too Many Attempts." — untranslated, and silent about the two things a
| locked-out user needs: how long the wait is, and that a second factor is not
| the only way in. Every limiter here answers for itself instead.
|
*/
it('answers a throttled request in the application\'s own words', function (): void {
    config()->set('nova-two-factor.rate_limits.enroll', ['per_user' => 1, 'per_ip' => 99, 'decay' => 120]);
    RateLimiter::clear('enroll|subject|'.$this->user->getAuthIdentifier().'@'.$this->user->getMorphClass());

    $enrol = fn () => $this->actingAs($this->user)
        ->postJson(novaUrl('methods'), ['type' => 'totp']);

    $enrol();
    $response = $enrol();

    $response->assertStatus(429);

    expect($response->json('message'))
        ->not->toBe('Too Many Attempts.')
        ->toContain('minutes');

    // Rendered under the field the user was typing into, or it shows nowhere.
    expect($response->json('errors.code.0'))->toBe($response->json('message'));
    expect($response->json('retry_after'))->toBeGreaterThan(0);
});

/**
 * "Use another method" is only true where another method exists. While
 * enrolling, the budget is shared across factors, so telling a locked-out user
 * to pick a different one sends them into the same wall.
 */
it('offers an alternative only where there is one', function (): void {
    [$method] = enrolledTotp($this->user);

    config()->set('nova-two-factor.rate_limits.enroll', ['per_user' => 1, 'per_ip' => 99, 'decay' => 120]);
    config()->set('nova-two-factor.rate_limits.challenge', ['per_user' => 1, 'per_ip' => 99, 'decay' => 120]);
    RateLimiter::clear('enroll|subject|'.$this->user->getAuthIdentifier().'@'.$this->user->getMorphClass());
    RateLimiter::clear('challenge|subject|'.$this->user->getAuthIdentifier().'@'.$this->user->getMorphClass());

    $enrol = fn () => $this->actingAs($this->user)->postJson(novaUrl('methods'), ['type' => 'email']);
    $enrol();

    expect($enrol()->json('message'))->not->toContain('use another method');

    $challenge = fn () => $this->actingAs($this->user)
        ->postJson(novaUrl('challenge'), ['method_id' => $method->id, 'code' => '000000']);
    $challenge();

    expect($challenge()->json('message'))->toContain('use another method');
});

/**
 * A reset that leaves the throttle buckets standing has not reset anything the
 * user can feel: the administrator is told it worked, and the user still meets
 * "try again in 4:39" on the screen they were just sent to.
 */
it('clears the throttle buckets when an administrator resets a user', function (): void {
    config()->set('nova-two-factor.rate_limits.enroll', ['per_user' => 1, 'per_ip' => 99, 'decay' => 600]);

    $enrol = fn () => $this->actingAs($this->user)->postJson(novaUrl('methods'), ['type' => 'totp']);

    $enrol();
    $enrol()->assertStatus(429);

    app(Gabrielesbaiz\NovaTwoFactor\Actions\ResetTwoFactor::class)($this->user, 'test');

    // The reset also ends sessions that predate it, so the user signs in again
    // — and lands on an enrollment screen that is no longer locked.
    $this->flushSession();

    $enrol()->assertSuccessful();
});

/**
 * Wiping somebody's second factor while their browser keeps a verified session
 * is half a revocation: the account stays open in whatever tab is already
 * logged in, counted as having cleared a factor that no longer exists.
 */
it('ends sessions that predate an administrative reset', function (): void {
    [$method] = enrolledTotp($this->user);

    $this->actingAs($this->user)->get(novaUrl('methods'))->assertOk();

    app(Gabrielesbaiz\NovaTwoFactor\Actions\ResetTwoFactor::class)($this->user, 'test');

    $this->actingAs($this->user)
        ->getJson(novaUrl('methods'))
        ->assertStatus(401)
        ->assertJson(['two_factor_reset' => true]);

    // A session started after the reset is unaffected.
    $this->flushSession();

    $this->actingAs($this->user)->get(novaUrl('methods'))->assertOk();
});

/**
 * The reset is the administrator's break-glass, so it has to clear the half of
 * the limiter keyed on the address too — otherwise a user who spent the
 * afternoon failing from their own office still meets the wall after being told
 * they were reset.
 */
it('clears the address buckets an administrator cannot see', function (): void {
    config()->set('nova-two-factor.rate_limits.enroll', ['per_user' => 99, 'per_ip' => 1, 'decay' => 600]);

    $enrol = fn () => $this->actingAs($this->user)
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->postJson(novaUrl('methods'), ['type' => 'totp']);

    $enrol();
    $enrol()->assertStatus(429);

    // The audit trail is where the reset learns which addresses to clear.
    app(Gabrielesbaiz\NovaTwoFactor\Actions\ResetTwoFactor::class)($this->user, 'test');
    $this->flushSession();

    $enrol()->assertSuccessful();
});

/**
 * Confirming an enrollment *is* a challenge: possession of that exact factor
 * was proved seconds ago. Sending the user straight to a second challenge for
 * the same factor is a toll, not a control — and on the mandatory-enrollment
 * path it is the first thing a new user meets.
 */
it('counts a confirmed enrollment as the session having passed', function (): void {
    $driver = app(TotpDriver::class);
    $intent = $driver->beginEnrollment($this->user);
    $secret = (string) $intent->secret;

    $this->actingAs($this->user)
        ->postJson(novaUrl('methods/confirm'), [
            'type' => 'totp',
            'code' => (new Google2FA)->getCurrentOtp($secret),
        ])
        ->assertSuccessful();

    // No second challenge for the factor just proved.
    $this->actingAs($this->user)->get(novaUrl('methods'))->assertOk();
});

/**
 * The hole behind "I logged out, logged back in, and went straight through".
 *
 * A flag that says only "this session passed" keeps saying it after the session
 * changes hands. Two independent guards now: logout clears it, and the flag
 * itself names the user it belongs to.
 */
it('does not carry a cleared factor across a logout', function (): void {
    [$method] = enrolledTotp($this->user);

    $session = new Gabrielesbaiz\NovaTwoFactor\Support\TwoFactorSession(app('session.store'));
    $session->markPassed($method, $this->user);

    expect($session->hasPassed($this->user))->toBeTrue();

    event(new Illuminate\Auth\Events\Logout(config('nova.guard') ?: 'web', $this->user));

    expect($session->hasPassed($this->user))->toBeFalse();
});

it('does not lend one user the verification of another', function (): void {
    [$method] = enrolledTotp($this->user);

    $other = User::factory()->create();

    $session = new Gabrielesbaiz\NovaTwoFactor\Support\TwoFactorSession(app('session.store'));
    $session->markPassed($method, $this->user);

    expect($session->hasPassed($this->user))->toBeTrue()
        ->and($session->hasPassed($other))->toBeFalse();
});

/**
 * Middleware aliases belong to the host application. Depending on
 * `password.confirm` meant an app on the Kernel layout that never registered it
 * answered every destructive route with "Target class [password.confirm] does
 * not exist" — a 500 where a password prompt belonged.
 */
it('guards destructive routes without depending on a host alias', function (): void {
    $guarded = collect(Route::getRoutes())
        ->filter(fn ($route): bool => str_contains((string) $route->getName(), 'nova-two-factor.'))
        ->flatMap(fn ($route): array => $route->gatherMiddleware())
        ->filter(fn ($middleware): bool => is_string($middleware) && str_contains($middleware, 'nova.password.confirm'))
        ->values();

    expect($guarded)->not->toBeEmpty();

    $guarded->each(function (string $middleware): void {
        expect($middleware)->toStartWith(Illuminate\Auth\Middleware\RequirePassword::class)
            ->and($middleware)->not->toStartWith('password.confirm');
    });
});

/**
 * A verification must not outlive the sign-in it belongs to.
 *
 * Logout clears it; so does login, because the two ends are touched by
 * different code in different applications and only one of them is ours.
 */
it('does not carry a cleared factor into a new sign-in', function (): void {
    [$method] = enrolledTotp($this->user);

    $session = new Gabrielesbaiz\NovaTwoFactor\Support\TwoFactorSession(app('session.store'));
    $session->markPassed($method, $this->user);

    expect($session->hasPassed($this->user))->toBeTrue();

    event(new Illuminate\Auth\Events\Login(config('nova.guard') ?: 'web', $this->user, false));

    expect($session->hasPassed($this->user))->toBeFalse();
});
