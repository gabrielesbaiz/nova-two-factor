<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Http\Controllers\ChallengeController;
use Gabrielesbaiz\NovaTwoFactor\RateLimiting\KnownDevice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Workbench\App\Models\User;

/**
 * The challenge limiter is keyed on the account, which is right against brute
 * force and wrong against sabotage: somebody holding a leaked password cannot
 * pass the challenge, but they can spend the owner's budget until the owner is
 * locked out of their own machine.
 */
function challengeUser(): User
{
    $user = User::factory()->create();

    $user->twoFactorMethods()->create([
        'type' => MethodType::Totp,
        'name' => 'Authenticator app',
        'secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',
        'confirmed_at' => now(),
    ]);

    return $user;
}

/**
 * A request carrying whatever cookie value is given.
 */
function requestWithDevice(?string $cookie): Request
{
    $request = Request::create('/nova/two-factor/challenge', 'POST');

    if ($cookie !== null) {
        $request->cookies->set(app(KnownDevice::class)->cookieName(), $cookie);
    }

    return $request;
}

it('gives a browser that cleared a challenge its own bucket', function (): void {
    $user = challengeUser();
    $device = app(KnownDevice::class);

    $issued = $device->issue($user, Request::create('/'));

    expect($issued)->not->toBeNull();

    $known = $device->idFor(requestWithDevice($issued->getValue()), $user);
    $unknown = $device->idFor(requestWithDevice(null), $user);

    expect($known)->toBeString()
        ->and($unknown)->toBeNull();
});

it('refuses a forged or tampered cookie', function (): void {
    // The whole design rests on this: if an attacker could mint identifiers,
    // every failure would land in a fresh bucket and the account's budget would
    // never be spent at all — brute-force protection gone, not improved.
    $user = challengeUser();
    $device = app(KnownDevice::class);

    $issued = $device->issue($user, Request::create('/'))->getValue();
    [$id, $signature] = explode('|', $issued, 2);

    expect($device->idFor(requestWithDevice('forged|'.$signature), $user))->toBeNull()
        ->and($device->idFor(requestWithDevice($id.'|deadbeef'), $user))->toBeNull()
        ->and($device->idFor(requestWithDevice($id), $user))->toBeNull()
        ->and($device->idFor(requestWithDevice(''), $user))->toBeNull();
});

it('does not let a cookie earned on one account buy a bucket on another', function (): void {
    // A shared machine: signing in as somebody else must not inherit their
    // allowance of wrong guesses.
    $owner = challengeUser();
    $other = challengeUser();
    $device = app(KnownDevice::class);

    $issued = $device->issue($owner, Request::create('/'))->getValue();

    expect($device->idFor(requestWithDevice($issued), $owner))->toBeString()
        ->and($device->idFor(requestWithDevice($issued), $other))->toBeNull();
});

it('keeps every unknown browser in one shared bucket', function (): void {
    // The attacker cannot escape the account's budget by arriving fresh each
    // time: with nothing we issued, every attempt is "unknown".
    $user = challengeUser();
    $device = app(KnownDevice::class);

    expect($device->idFor(requestWithDevice(null), $user))->toBeNull()
        ->and($device->idFor(requestWithDevice('whatever|nonsense'), $user))->toBeNull();
});

it('reuses the identifier a browser already has', function (): void {
    // Otherwise every successful sign-in would move the browser to a new
    // bucket, and the budget it had been spending would be forgotten.
    $user = challengeUser();
    $device = app(KnownDevice::class);

    $first = $device->issue($user, Request::create('/'))->getValue();
    $second = $device->issue($user, requestWithDevice($first))->getValue();

    expect($second)->toBe($first);
});

it('falls back to one bucket per account when the split is turned off', function (): void {
    config()->set('nova-two-factor.rate_limits.per_device', false);

    $user = challengeUser();
    $device = app(KnownDevice::class);

    expect($device->issue($user, Request::create('/')))->toBeNull()
        ->and($device->idFor(requestWithDevice('anything|at-all'), $user))->toBeNull();
});

it('leaves the owner a working budget while an attacker burns the shared one', function (): void {
    // The scenario the split exists for, end to end: the password is leaked,
    // the attacker cannot pass the challenge, and the question is whether the
    // owner can still sign in from the laptop they were already using.
    $user = challengeUser();
    $device = app(KnownDevice::class);
    $limits = config('nova-two-factor.rate_limits.challenge');

    $owners = $device->issue($user, Request::create('/'))->getValue();

    $attackerKey = (new ReflectionMethod(ChallengeController::class, 'limiterKey'))
        ->invoke(app(ChallengeController::class), requestWithDevice(null), $user);

    $ownerKey = (new ReflectionMethod(ChallengeController::class, 'limiterKey'))
        ->invoke(app(ChallengeController::class), requestWithDevice($owners), $user);

    expect($attackerKey)->not->toBe($ownerKey);

    // The attacker spends everything the shared bucket holds, several times over.
    foreach (range(1, $limits['per_user'] * 4) as $ignored) {
        RateLimiter::hit($attackerKey, 60);
    }

    expect(RateLimiter::tooManyAttempts($attackerKey, $limits['per_user']))->toBeTrue()
        ->and(RateLimiter::tooManyAttempts($ownerKey, $limits['per_user']))->toBeFalse();
});
