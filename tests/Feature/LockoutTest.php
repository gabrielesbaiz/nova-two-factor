<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Alerts\LockoutBurstDetected;
use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Events\LockedOut;
use Gabrielesbaiz\NovaTwoFactor\Http\Middleware\RequireTwoFactor;
use Gabrielesbaiz\NovaTwoFactor\Listeners\DetectLockoutBurst;
use Gabrielesbaiz\NovaTwoFactor\TrustedDevices\TrustedDeviceManager;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Workbench\App\Models\User;

/**
 * A lockout stops one account being guessed at. Aimed deliberately, at an
 * address somebody already holds the password for, it stops that account
 * working — so what matters is what survives it, and whether anybody is told.
 */
function lockedOutFor(User $user): LockedOut
{
    return new LockedOut($user, null, ['retry_after' => 60]);
}

function totpUser(): User
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

it('lets a trusted device through while the account is locked out', function (): void {
    // The carve-out that makes a lockout survivable: the attacker's browser is
    // refused, and the victim's own laptop — which already cleared a challenge
    // once — keeps working. Without it, one credential dump stops the panel for
    // everybody in it.
    $user = totpUser();

    $manager = app(TrustedDeviceManager::class);
    $cookie = $manager->trust($user, request());

    // Spend the whole challenge budget, as an attacker with the password would.
    $key = 'nova-two-factor:challenge|'.$user->getMorphClass().'|'.$user->getAuthIdentifier();

    for ($attempt = 0; $attempt < 20; $attempt++) {
        app('Illuminate\Cache\RateLimiter')->hit($key, 60);
    }

    $request = Request::create('/nova/dashboards/main', 'GET');
    $request->cookies->set($cookie->getName(), $cookie->getValue());
    $request->headers->set('User-Agent', (string) request()->userAgent());
    $request->setUserResolver(fn (): User => $user);
    $request->setLaravelSession(session()->driver());

    $reached = false;

    app(RequireTwoFactor::class)->handle($request, function () use (&$reached) {
        $reached = true;

        return new Response('ok');
    });

    expect($reached)->toBeTrue();
});

it('says nothing when a single account locks itself out repeatedly', function (): void {
    // One person fumbling their authenticator is the control working, however
    // many times they do it. Paging somebody for that teaches them to mute it.
    config()->set('nova-two-factor.alerts.lockout_burst.accounts', 3);

    Event::fake([LockoutBurstDetected::class]);

    $user = totpUser();
    $listener = app(DetectLockoutBurst::class);

    foreach (range(1, 10) as $ignored) {
        $listener->handle(lockedOutFor($user));
    }

    Event::assertNotDispatched(LockoutBurstDetected::class);
});

it('raises a burst once enough distinct accounts lock inside the window', function (): void {
    config()->set('nova-two-factor.alerts.lockout_burst.accounts', 3);
    config()->set('nova-two-factor.alerts.lockout_burst.window_minutes', 15);

    Event::fake([LockoutBurstDetected::class]);

    $listener = app(DetectLockoutBurst::class);

    $listener->handle(lockedOutFor(totpUser()));
    $listener->handle(lockedOutFor(totpUser()));

    Event::assertNotDispatched(LockoutBurstDetected::class);

    $listener->handle(lockedOutFor(totpUser()));

    Event::assertDispatched(LockoutBurstDetected::class, function (LockoutBurstDetected $event): bool {
        return $event->accounts === 3 && $event->threshold === 3 && $event->windowMinutes === 15;
    });
});

it('raises it once, not on every lockout after it', function (): void {
    config()->set('nova-two-factor.alerts.lockout_burst.accounts', 2);

    Event::fake([LockoutBurstDetected::class]);

    $listener = app(DetectLockoutBurst::class);

    $listener->handle(lockedOutFor(totpUser()));
    $listener->handle(lockedOutFor(totpUser()));
    $listener->handle(lockedOutFor(totpUser()));
    $listener->handle(lockedOutFor(totpUser()));

    Event::assertDispatchedTimes(LockoutBurstDetected::class, 1);
});

it('forgets lockouts older than the window', function (): void {
    // A quiet hour resets the count: three accounts locking across a whole
    // afternoon is a support queue, not an attack.
    config()->set('nova-two-factor.alerts.lockout_burst.accounts', 3);
    config()->set('nova-two-factor.alerts.lockout_burst.window_minutes', 15);

    Event::fake([LockoutBurstDetected::class]);

    $listener = app(DetectLockoutBurst::class);

    $listener->handle(lockedOutFor(totpUser()));
    $listener->handle(lockedOutFor(totpUser()));

    $this->travel(20)->minutes();

    $listener->handle(lockedOutFor(totpUser()));

    Event::assertNotDispatched(LockoutBurstDetected::class);
});

it('stays silent until an operator sets a threshold', function (): void {
    expect(config('nova-two-factor.alerts.lockout_burst.accounts'))->toBe(0);

    Event::fake([LockoutBurstDetected::class]);

    $listener = app(DetectLockoutBurst::class);

    foreach (range(1, 10) as $ignored) {
        $listener->handle(lockedOutFor(totpUser()));
    }

    Event::assertNotDispatched(LockoutBurstDetected::class);

    expect(Cache::get('nova-two-factor:lockout-burst'))->toBeNull();
});
