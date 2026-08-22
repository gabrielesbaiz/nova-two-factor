<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Http\Middleware\RequireTwoFactor;
use Gabrielesbaiz\NovaTwoFactor\Http\Middleware\RequireTwoFactorEnrollment;
use Gabrielesbaiz\NovaTwoFactor\StepUp\StepUpManager;
use Gabrielesbaiz\NovaTwoFactor\Support\Enforcement;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\Admin;
use Workbench\App\Models\User;

beforeEach(function (): void {
    $this->enforcement = app(Enforcement::class);
    Enforcement::requireUsing(null);
});

afterEach(function (): void {
    Enforcement::requireUsing(null);
});

/**
 * A request carrying a real session store, which is what a grant genuinely
 * needs — the manager now refuses to issue one without it rather than handing
 * back a grant that is never stored.
 *
 * @return array{0: StepUpManager, 1: Request}
 */
function stepUpContext(): array
{
    $request = Request::create('/nova/resources/users/1', 'GET');
    $request->setLaravelSession(app('session.store'));

    return [app(StepUpManager::class), $request];
}

it('does not enforce in optional mode', function (): void {
    config()->set('nova-two-factor.enforcement.mode', 'optional');

    $user = User::factory()->create();

    expect($this->enforcement->appliesTo($user))->toBeFalse()
        ->and($this->enforcement->blocks($user))->toBeFalse();
});

it('does not block in encouraged mode', function (): void {
    config()->set('nova-two-factor.enforcement.mode', 'encouraged');

    $user = User::factory()->create(['created_at' => now()->subYears(2)]);

    // In scope for the prompt, but never blocked.
    expect($this->enforcement->appliesTo($user))->toBeTrue()
        ->and($this->enforcement->blocks($user))->toBeFalse();
});

it('allows a user through while they are inside the grace window', function (): void {
    config()->set('nova-two-factor.enforcement.mode', 'required');
    config()->set('nova-two-factor.enforcement.grace_days', 7);

    $user = User::factory()->create(['created_at' => now()->subDays(2)]);

    expect($this->enforcement->blocks($user))->toBeFalse()
        ->and($this->enforcement->graceEndsAt($user)->isFuture())->toBeTrue();
});

it('blocks once the grace window has closed', function (): void {
    config()->set('nova-two-factor.enforcement.mode', 'required');
    config()->set('nova-two-factor.enforcement.grace_days', 7);

    $user = User::factory()->create(['created_at' => now()->subDays(30)]);

    expect($this->enforcement->blocks($user))->toBeTrue();
});

it('measures grace from a configured cutover date when there is one', function (): void {
    config()->set('nova-two-factor.enforcement.mode', 'required');
    config()->set('nova-two-factor.enforcement.grace_days', 10);
    config()->set('nova-two-factor.enforcement.enforced_from', now()->subDays(3)->toDateString());

    // An account created years ago is still inside the organisation-wide window.
    $user = User::factory()->create(['created_at' => now()->subYears(5)]);

    expect($this->enforcement->blocks($user))->toBeFalse();
});

it('stops blocking as soon as a factor is confirmed', function (): void {
    config()->set('nova-two-factor.enforcement.mode', 'required');

    $user = User::factory()->create(['created_at' => now()->subYears(1)]);

    expect($this->enforcement->blocks($user))->toBeTrue();

    $user->twoFactorMethods()->create([
        'type' => MethodType::Totp,
        'name' => 'App',
        'secret' => 'JBSWY3DPEHPK3PXP',
        'confirmed_at' => now(),
    ]);

    expect($this->enforcement->blocks($user->refresh()))->toBeFalse();
});

it('does not count an unconfirmed enrollment as compliance', function (): void {
    config()->set('nova-two-factor.enforcement.mode', 'required');

    $user = User::factory()->create(['created_at' => now()->subYears(1)]);
    $user->twoFactorMethods()->create(['type' => MethodType::Totp, 'name' => 'Pending', 'secret' => 'X']);

    expect($this->enforcement->blocks($user->refresh()))->toBeTrue();
});

it('targets only the users a configured gate allows', function (): void {
    config()->set('nova-two-factor.enforcement.mode', 'required');
    config()->set('nova-two-factor.enforcement.gate', 'require-two-factor');

    Gate::define('require-two-factor', fn ($user): bool => str_ends_with($user->email, '@staff.test'));

    $staff = User::factory()->create(['email' => 'a@staff.test', 'created_at' => now()->subYears(1)]);
    $outsider = User::factory()->create(['email' => 'b@other.test', 'created_at' => now()->subYears(1)]);

    // Role logic is delegated to the host app's own authorization layer rather
    // than reinvented here.
    expect($this->enforcement->blocks($staff))->toBeTrue()
        ->and($this->enforcement->blocks($outsider))->toBeFalse();
});

it('lets the tool callback override everything else', function (): void {
    config()->set('nova-two-factor.enforcement.mode', 'required');

    $user = User::factory()->create(['created_at' => now()->subYears(1)]);

    Enforcement::requireUsing(fn (): bool => false);

    expect($this->enforcement->appliesTo($user))->toBeFalse()
        ->and($this->enforcement->blocks($user))->toBeFalse();
});

it('builds its except-list from the configured Nova path', function (): void {
    config()->set('nova.path', '/backoffice/admin');

    $patterns = $this->enforcement->exceptPatterns();

    // 1.x hardcoded `admin/login`, so any other path produced a redirect loop.
    expect($patterns)->toContain('backoffice/admin/login')
        ->and($patterns)->toContain('backoffice/admin/two-factor/*')
        ->and($patterns)->not->toContain('admin/login');
});

it('leaves the challenge and enrollment routes reachable', function (): void {
    $patterns = $this->enforcement->exceptPatterns();
    $prefix = trim((string) config('nova.path'), '/');

    // Locking somebody out of the page that unlocks them is how a 2FA gate
    // becomes an outage.
    foreach (["{$prefix}/two-factor/*", "{$prefix}/login", "{$prefix}/logout"] as $required) {
        expect($patterns)->toContain($required);
    }
});

it('enforces the same way for a second authenticatable model', function (): void {
    config()->set('nova-two-factor.enforcement.mode', 'required');

    $admin = Admin::factory()->create(['created_at' => now()->subYears(1)]);

    expect($this->enforcement->blocks($admin))->toBeTrue();

    $admin->twoFactorMethods()->create([
        'type' => MethodType::Totp,
        'name' => 'App',
        'secret' => 'JBSWY3DPEHPK3PXP',
        'confirmed_at' => now(),
    ]);

    expect($this->enforcement->blocks($admin->refresh()))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The guarantee, as a test rather than a claim
|--------------------------------------------------------------------------
*/
it('guards both Nova middleware groups, not just the pages', function (): void {
    // The 1.x hole: enforcement was wired only into the page middleware, so the
    // whole policy could be sidestepped by talking to nova-api/* directly.
    foreach (['nova.middleware', 'nova.api_middleware'] as $group) {
        expect(config($group))
            ->toContain(RequireTwoFactorEnrollment::class)
            ->toContain(RequireTwoFactor::class);
    }
});

it('leaves no package route unguarded or unexcepted', function (): void {
    $prefix = trim((string) config('nova.path'), '/');
    $patterns = app(Enforcement::class)->exceptPatterns();

    $unaccounted = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_starts_with((string) $route->uri(), $prefix.'/two-factor'))
        ->reject(function ($route) use ($patterns): bool {
            $uri = (string) $route->uri();

            foreach ($patterns as $pattern) {
                $regex = '#^'.str_replace(['*', '/'], ['.*', '\/'], $pattern).'$#';

                if (preg_match($regex, $uri) === 1) {
                    return true;
                }
            }

            return false;
        });

    // Every two-factor route must be deliberately excepted, or a non-compliant
    // user has nowhere to go. This test is what will fail the day somebody adds
    // a route outside the except-list.
    expect($unaccounted->pluck('uri')->all())->toBe([]);
});

/*
|--------------------------------------------------------------------------
| Step-up scoping
|--------------------------------------------------------------------------
*/
it('scopes a grant to the exact scope it was issued for', function (): void {
    $user = User::factory()->create();
    [$manager, $request] = stepUpContext();

    $manager->grant($request, $user, 'users.destroy', 'totp');

    expect($manager->has($request, $user, 'users.destroy'))->toBeTrue()
        // Proving yourself for one sensitive action must not unlock another.
        ->and($manager->has($request, $user, 'settings.write'))->toBeFalse();
});

it('expires a grant', function (): void {
    config()->set('nova-two-factor.step_up.ttl', 60);

    $user = User::factory()->create();
    [$manager, $request] = stepUpContext();

    $manager->grant($request, $user, 'users.destroy', 'totp');

    $this->travel(120)->seconds();

    expect($manager->has($request, $user, 'users.destroy'))->toBeFalse();
});

it('rejects a grant whose signature has been tampered with', function (): void {
    $user = User::factory()->create();
    [$manager, $request] = stepUpContext();

    $manager->grant($request, $user, 'users.destroy', 'totp');

    // Rewrite the stored grant to claim a different scope. The signature covers
    // the scope, so this must not validate — which is exactly why the grant is
    // signed even though it already lives in the session.
    $stored = $request->session()->get('nova_two_factor.step_up');
    $stored['settings.write'] = array_merge($stored['users.destroy'], ['scope' => 'settings.write']);
    $request->session()->put('nova_two_factor.step_up', $stored);

    expect($manager->has($request, $user, 'settings.write'))->toBeFalse();
});

it('rejects a grant belonging to a different user', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    [$manager, $request] = stepUpContext();

    $manager->grant($request, $user, 'users.destroy', 'totp');

    expect($manager->has($request, $other, 'users.destroy'))->toBeFalse();
});

it('matches protected patterns by method and wildcard', function (): void {
    config()->set('nova-two-factor.step_up.protect', [
        'users.destroy' => ['DELETE nova-api/users/*'],
        'settings' => ['* nova-api/settings*'],
    ]);

    $manager = app(StepUpManager::class);

    $delete = Request::create('/nova-api/users/12', 'DELETE');
    $get = Request::create('/nova-api/users/12', 'GET');
    $settings = Request::create('/nova-api/settings/general', 'POST');

    // 1.x compared `$request->path()` against a flat array with `in_array`, so
    // no pattern ever matched anything at all.
    expect($manager->scopeFor($delete))->toBe('users.destroy')
        ->and($manager->scopeFor($get))->toBeNull()
        ->and($manager->scopeFor($settings))->toBe('settings');
});
