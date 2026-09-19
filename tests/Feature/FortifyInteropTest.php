<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Auth\SupersedeFortifyTwoFactorChallenge;
use Gabrielesbaiz\NovaTwoFactor\ToolServiceProvider;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable as FortifyAction;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Nova\Auth\Actions\RedirectIfTwoFactorAuthenticatable as NovaAction;

/**
 * A user carrying Fortify's own two-factor columns — the shape this package
 * never created, but routinely inherits: a 1.x install, Nova's built-in
 * two-factor, or a second guard running its own Fortify implementation on the
 * same user table.
 */
function legacyFortifyUser(): Authenticatable
{
    return new class extends Authenticatable
    {
        use TwoFactorAuthenticatable;

        protected $table = 'users';

        protected $attributes = [
            'two_factor_secret' => 'encrypted-blob',
        ];

        public function getAttribute($key): mixed
        {
            return $key === 'two_factor_confirmed_at'
                ? now()
                : parent::getAttribute($key);
        }
    };
}

it('resolves the superseding action by default', function (): void {
    expect(app()->make(FortifyAction::class))
        ->toBeInstanceOf(SupersedeFortifyTwoFactorChallenge::class);
});

it('lets a legacy Fortify enrollment through to this package instead of diverting it', function (): void {
    $action = new class extends SupersedeFortifyTwoFactorChallenge
    {
        public function __construct() {}

        protected function validateCredentials($request): mixed
        {
            return legacyFortifyUser();
        }
    };

    $reached = false;

    $response = $action->handle(Request::create('/nova/login', 'POST'), function () use (&$reached) {
        $reached = true;

        return 'next';
    });

    expect($reached)->toBeTrue()
        ->and($response)->toBe('next');
});

it('proves the unsuperseded action would have diverted the same user', function (): void {
    $action = new class extends NovaAction
    {
        public function __construct() {}

        protected function validateCredentials($request): mixed
        {
            return legacyFortifyUser();
        }
    };

    $reached = false;

    $next = function () use (&$reached) {
        $reached = true;

        return 'next';
    };

    // Fortify redirects to `two-factor.login`, a route a Nova-only install does
    // not register, so the divert surfaces as a routing failure here. Either
    // way the assertion that matters is the same: the pipeline never continued.
    rescue(fn () => $action->handle(Request::create('/nova/login', 'POST'), $next), report: false);

    expect($reached)->toBeFalse();
});

it('leaves Fortify in charge when superseding is turned off', function (): void {
    config()->set('nova-two-factor.fortify.supersede_challenge', false);

    app()->forgetExtenders(FortifyAction::class);

    (new ToolServiceProvider(app()))->boot();

    expect(app()->make(FortifyAction::class))
        ->not->toBeInstanceOf(SupersedeFortifyTwoFactorChallenge::class);
});

it('leaves Fortify in charge when the package master switch is off', function (): void {
    config()->set('nova-two-factor.enabled', false);

    app()->forgetExtenders(FortifyAction::class);

    (new ToolServiceProvider(app()))->boot();

    expect(app()->make(FortifyAction::class))
        ->not->toBeInstanceOf(SupersedeFortifyTwoFactorChallenge::class);
});
