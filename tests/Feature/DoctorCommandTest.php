<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Http\Middleware\RequireTwoFactor;
use Gabrielesbaiz\NovaTwoFactor\Http\Middleware\RequireTwoFactorEnrollment;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable as FortifyAction;

it('passes on a correctly configured application', function (): void {
    $this->artisan('nova-two-factor:doctor')->assertSuccessful();
});

it('fails when the enforcement middleware is missing', function (): void {
    // The single most common 1.x install problem, and it produced no error at
    // all: `mandatory => true` with the middleware never registered.
    config()->set('nova.middleware', array_values(array_diff(
        config('nova.middleware', []),
        [RequireTwoFactor::class, RequireTwoFactorEnrollment::class],
    )));

    $this->artisan('nova-two-factor:doctor')->assertFailed();
});

it('fails when a 1.x configuration key is still published', function (): void {
    config()->set('nova-two-factor.use_google_qr_code_api', true);

    // A stale published config is otherwise ignored in silence, so an operator
    // believes a setting applies when it does not.
    $this->artisan('nova-two-factor:doctor')->assertFailed();
});

it('fails when every method is disabled', function (): void {
    foreach (['totp', 'webauthn', 'email'] as $type) {
        config()->set("nova-two-factor.methods.{$type}.enabled", false);
    }

    $this->artisan('nova-two-factor:doctor')->assertFailed();
});

it('fails when passkeys are enabled without https', function (): void {
    config()->set('app.url', 'http://admin.example.test');
    config()->set('nova-two-factor.methods.webauthn.enabled', true);

    // Browsers refuse the ceremony outside a secure context, and the error they
    // produce names nothing useful.
    $this->artisan('nova-two-factor:doctor')->assertFailed();
});

it('fails when the relying party id is not a parent of the app host', function (): void {
    config()->set('nova-two-factor.methods.webauthn.relying_party.id', 'unrelated.test');

    $this->artisan('nova-two-factor:doctor')->assertFailed();
});

it('fails when a configured enforcement gate does not exist', function (): void {
    config()->set('nova-two-factor.enforcement.mode', 'required');
    config()->set('nova-two-factor.enforcement.gate', 'gate-that-does-not-exist');

    // Otherwise enforcement is on, nobody is in scope, and nothing says so.
    $this->artisan('nova-two-factor:doctor')->assertFailed();
});

it('fails when something else has taken back the Fortify challenge', function (): void {
    // A login that lands on Fortify's challenge instead of ours looks like a
    // working login, logs nothing, and simply never offers this package's
    // methods — so only an explicit check finds it.
    app()->forgetExtenders(FortifyAction::class);

    $this->artisan('nova-two-factor:doctor')->assertFailed();
});

it('passes with a warning when superseding the Fortify challenge is turned off', function (): void {
    config()->set('nova-two-factor.fortify.supersede_challenge', false);

    // A deliberate choice, not a misconfiguration: Fortify owns the challenge.
    $this->artisan('nova-two-factor:doctor')->assertSuccessful();
});

it('prunes expired records', function (): void {
    $this->artisan('nova-two-factor:prune')->assertSuccessful();
});

it('reports nothing to migrate when there is no legacy table', function (): void {
    $this->artisan('nova-two-factor:upgrade')->assertSuccessful();
});
