<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\RateLimiting\KnownDevice;
use Gabrielesbaiz\NovaTwoFactor\Support\CookieSecurity;
use Gabrielesbaiz\NovaTwoFactor\TrustedDevices\TrustedDeviceManager;
use Illuminate\Http\Request;
use Workbench\App\Models\User;

/**
 * A trusted-device cookie is a thirty-day skip past the challenge, and the
 * request cannot be trusted to say whether it arrived over TLS: behind a proxy
 * that terminates it, PHP sees plain HTTP unless `TrustProxies` is configured.
 */
function plainRequest(): Request
{
    // What a proxy forwards to PHP: the browser is on https, this is not.
    return Request::create('http://admin.example.test/nova/two-factor/challenge', 'POST');
}

it('takes the application session cookie policy over what the request claims', function (): void {
    config()->set('nova-two-factor.cookies.secure', null);
    config()->set('session.secure', true);

    expect(CookieSecurity::secure(plainRequest()))->toBeTrue();
});

it('lets the package setting override both', function (): void {
    config()->set('nova-two-factor.cookies.secure', true);
    config()->set('session.secure', false);

    expect(CookieSecurity::secure(plainRequest()))->toBeTrue();

    config()->set('nova-two-factor.cookies.secure', false);
    config()->set('session.secure', true);

    expect(CookieSecurity::secure(plainRequest()))->toBeFalse();
});

it('falls back to the request when the application says nothing', function (): void {
    config()->set('nova-two-factor.cookies.secure', null);
    config()->set('session.secure', null);

    expect(CookieSecurity::secure(plainRequest()))->toBeFalse()
        ->and(CookieSecurity::secure(Request::create('https://admin.example.test/', 'GET')))->toBeTrue();
});

it('marks both cookies secure behind a TLS-terminating proxy', function (): void {
    // The bug this closes, on the objects that ship the cookies.
    config()->set('session.secure', true);

    $user = User::factory()->create();

    $trusted = app(TrustedDeviceManager::class)->trust($user, plainRequest());
    $known = app(KnownDevice::class)->issue($user, plainRequest());

    expect($trusted->isSecure())->toBeTrue()
        ->and($known->isSecure())->toBeTrue();
});

it('warns in the doctor when an https app leaves cookie security unset', function (): void {
    config()->set('app.url', 'https://admin.example.test');
    config()->set('session.secure', null);
    config()->set('nova-two-factor.cookies.secure', null);

    // A warning rather than a failure: with TrustProxies configured the
    // request is seen as secure and nothing is wrong. The point is to say so,
    // because the alternative failure is silent.
    $this->artisan('nova-two-factor:doctor')
        ->expectsOutputToContain('ships without Secure')
        ->assertSuccessful();
});

it('passes the doctor once the session cookie is secure', function (): void {
    config()->set('app.url', 'https://admin.example.test');
    config()->set('session.secure', true);
    config()->set('nova-two-factor.cookies.secure', null);

    $this->artisan('nova-two-factor:doctor')
        ->expectsOutputToContain('Following session.secure')
        ->assertSuccessful();
});

it('says nothing about cookies on a plain http development site', function (): void {
    config()->set('app.url', 'http://nova.test');
    config()->set('session.secure', null);
    config()->set('nova-two-factor.cookies.secure', null);
    // Passkeys need a secure context and fail their own check on http, which is
    // not what this test is about.
    config()->set('nova-two-factor.methods.webauthn.enabled', false);

    $this->artisan('nova-two-factor:doctor')
        ->expectsOutputToContain('nothing to enforce')
        ->assertSuccessful();
});
