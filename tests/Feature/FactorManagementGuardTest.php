<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Http\Middleware\RequireVerifiedSession;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Gabrielesbaiz\NovaTwoFactor\Recovery\RecoveryCodeManager;
use Gabrielesbaiz\NovaTwoFactor\Support\TwoFactorSession;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PragmaRX\Google2FA\Google2FA;
use Workbench\App\Models\User;

/**
 * The two-factor prefix is exempt from the challenge middleware — the challenge
 * screen lives there — so every route under it that hands out, or destroys, a
 * second factor has to check for itself.
 */
function enrolledUser(): User
{
    $user = User::factory()->create();

    $user->twoFactorMethods()->create([
        'type' => MethodType::Email,
        'name' => 'Email code',
        'destination' => $user->email,
        'destination_hint' => 'u****@example.com',
        'confirmed_at' => now(),
    ]);

    return $user;
}

function passed(User $user): bool
{
    return (new TwoFactorSession(session()->driver()))->hasPassed($user);
}

it('refuses to enroll a new factor for a session that never cleared the challenge', function (): void {
    // The attack this closes: password stolen, challenge unanswered. Two
    // requests used to buy a working factor and a verified session.
    $user = enrolledUser();

    $this->actingAs($user)
        ->postJson('/nova/two-factor/methods', ['type' => 'totp'])
        ->assertStatus(423)
        ->assertJson(['two_factor_required' => true]);

    expect(TwoFactorMethod::query()->where('type', 'totp')->count())->toBe(0);
});

it('refuses to confirm an enrollment for a session that never cleared the challenge', function (): void {
    // Even holding a secret from elsewhere, confirming is refused — and the
    // session is not upgraded on the way out.
    $user = enrolledUser();

    $this->actingAs($user)
        ->postJson('/nova/two-factor/methods/confirm', [
            'type' => 'totp',
            'code' => (new Google2FA)->getCurrentOtp('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'),
        ])
        ->assertStatus(423);

    expect(passed($user))->toBeFalse();
});

it('refuses to regenerate recovery codes for a session that never cleared the challenge', function (): void {
    // Regenerating returns the plaintext codes, and every one of them answers a
    // challenge — so a password confirmation alone cannot be the only gate.
    $user = enrolledUser();

    $this->actingAs($user)
        ->postJson('/nova/two-factor/recovery-codes')
        ->assertStatus(423);

    expect(app(RecoveryCodeManager::class)->unusedCount($user))->toBe(0);
});

it('still lets an account with nothing enrolled set up its first factor', function (): void {
    // The bootstrap case, and the reason the guard cannot simply demand a
    // cleared challenge: there is no factor to clear one with.
    $user = User::factory()->create();

    $start = $this->actingAs($user)->postJson('/nova/two-factor/methods', ['type' => 'totp']);

    $start->assertOk();

    $secret = $start->json('secret');

    expect($secret)->toBeString();

    $this->postJson('/nova/two-factor/methods/confirm', [
        'type' => 'totp',
        'code' => (new Google2FA)->getCurrentOtp($secret),
    ])->assertOk();

    // Confirming a first factor is itself a challenge: the user proved this
    // exact factor seconds ago and had no other to be challenged on.
    expect(passed($user))->toBeTrue();
});

it('lets a verified session manage its factors as before', function (): void {
    $user = enrolledUser();

    $this->actingAs($user);
    (new TwoFactorSession(session()->driver()))->markPassed(null, $user);

    $this->postJson('/nova/two-factor/methods', ['type' => 'totp'])->assertOk();
});

it('accepts a trusted device in place of a challenge', function (): void {
    // "Remember this device" is a challenge already cleared. Without this the
    // guard would lock people out of their own security page.
    //
    // Driven through the middleware rather than the HTTP kernel: what matters
    // here is the decision, and the test harness cannot hand a route the
    // encrypted cookie a browser would.
    $user = enrolledUser();
    $this->actingAs($user);

    $manager = app(Gabrielesbaiz\NovaTwoFactor\TrustedDevices\TrustedDeviceManager::class);
    $cookie = $manager->trust($user, request());

    $request = Request::create('/nova/two-factor/methods', 'POST');
    $request->cookies->set($cookie->getName(), $cookie->getValue());
    $request->headers->set('User-Agent', (string) request()->userAgent());
    $request->setUserResolver(fn (): User => $user);
    $request->setLaravelSession(session()->driver());

    $reached = false;

    $response = app(RequireVerifiedSession::class)->handle($request, function () use (&$reached) {
        $reached = true;

        return new Response('ok');
    });

    expect($reached)->toBeTrue()
        ->and($response->getStatusCode())->toBe(200)
        // And the session is upgraded on the way through, so the next request
        // does not repeat the lookup.
        ->and(passed($user))->toBeTrue();
});
