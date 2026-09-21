<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Enums\EnforcementMode;
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

function novaTwoFactorUrl(string $path): string
{
    $prefix = trim((string) config('nova.path'), '/');
    $segment = trim((string) config('nova-two-factor.routes.prefix', 'two-factor'), '/');

    return '/'.trim($prefix.'/'.$segment.'/'.ltrim($path, '/'), '/');
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

it('treats a configured date as the deadline everyone shares', function (): void {
    // It reads as "everyone must comply by 1 October", so that is what it now
    // means. Treating it as a *start* to count grace days from quietly gave
    // every account the cutover date plus the window on top.
    config()->set('nova-two-factor.enforcement.mode', 'required');
    config()->set('nova-two-factor.enforcement.grace_mode', 'date');
    config()->set('nova-two-factor.enforcement.grace_days', 10);

    $user = User::factory()->create(['created_at' => now()->subYears(5)]);

    config()->set('nova-two-factor.enforcement.enforced_from', now()->addDays(3)->toDateString());
    expect($this->enforcement->blocks($user))->toBeFalse();

    config()->set('nova-two-factor.enforcement.enforced_from', now()->subDays(3)->toDateString());
    expect($this->enforcement->blocks($user))->toBeTrue();
});

it('blocks immediately when grace is switched off', function (): void {
    config()->set('nova-two-factor.enforcement.mode', 'required');
    config()->set('nova-two-factor.enforcement.grace_enabled', false);
    config()->set('nova-two-factor.enforcement.grace_days', 90);

    // The days are still configured; the switch is what decides whether they
    // are consulted at all.
    expect($this->enforcement->blocks(User::factory()->create()))->toBeTrue();
});

it('counts the days a user has left', function (): void {
    config()->set('nova-two-factor.enforcement.mode', 'required');
    config()->set('nova-two-factor.enforcement.grace_days', 7);

    $user = User::factory()->create(['created_at' => now()->subDays(2)]);

    // Rounded up: "0 days left" on the morning of the deadline is true to the
    // hour and useless to whoever still has today to act.
    expect($this->enforcement->graceDaysLeft($user))->toBe(5)
        ->and($this->enforcement->shouldWarn($user))->toBeTrue();

    config()->set('nova-two-factor.enforcement.grace_days', 1);

    expect($this->enforcement->graceDaysLeft($user))->toBe(0)
        ->and($this->enforcement->shouldWarn($user))->toBeFalse();
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

/*
|--------------------------------------------------------------------------
| Encouraged: a prompt, not a wall
|--------------------------------------------------------------------------
*/

it('shows the enrollment page to an unenrolled user under encouragement', function (): void {
    config()->set('nova-two-factor.enforcement.mode', EnforcementMode::Encouraged->value);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/'.trim(trim((string) config('nova.path'), '/').'/dashboards/main', '/'))
        ->assertRedirect(novaTwoFactorUrl('required'));

    $this->actingAs($user)
        ->get(novaTwoFactorUrl('required'))
        ->assertOk()
        ->assertSee('Protect your account with a second factor', false)
        ->assertSee('Not now', false)
        // Never a deadline in a mode that has none.
        ->assertDontSee('Set up a method to continue.', false);
});

it('never blocks a request under encouragement', function (): void {
    config()->set('nova-two-factor.enforcement.mode', EnforcementMode::Encouraged->value);

    $user = User::factory()->create();

    // XHR keeps working: a reminder that answers a data call with HTML is a bug.
    $this->actingAs($user)
        ->getJson('/'.trim(trim((string) config('nova.path'), '/').'/nova-api/scripts/x', '/'))
        ->assertStatus(404);
});

it('stays quiet for the configured number of days once waved away', function (): void {
    config()->set('nova-two-factor.enforcement.mode', EnforcementMode::Encouraged->value);
    config()->set('nova-two-factor.enforcement.remind_every_days', 3);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(novaTwoFactorUrl('required/remind-later'), ['snooze' => '1'])
        ->assertRedirect();

    $enforcement = app(Enforcement::class);

    expect($enforcement->shouldRemind($user))->toBeFalse();

    $this->travel(4)->days();

    expect($enforcement->shouldRemind($user))->toBeTrue();
});

/**
 * Unticked buys this session, ticked buys days. Recording nothing at all sent
 * the user back to the page they had just dismissed, on the next request.
 */
it('stops asking for the rest of the session when the box is left unticked', function (): void {
    config()->set('nova-two-factor.enforcement.mode', EnforcementMode::Encouraged->value);

    $user = User::factory()->create();
    $dashboard = '/'.trim(trim((string) config('nova.path'), '/').'/dashboards/main', '/');

    $this->actingAs($user)->get($dashboard)->assertRedirect(novaTwoFactorUrl('required'));

    $this->actingAs($user)->post(novaTwoFactorUrl('required/remind-later'))->assertRedirect();

    // Same session: whatever Nova itself answers, it is no longer a bounce back
    // to the page just dismissed.
    $again = $this->actingAs($user)->get($dashboard);

    expect($again->headers->get('Location'))->not->toBe(url(novaTwoFactorUrl('required')));

    // The account itself is not snoozed — a fresh session asks again.
    expect(app(Enforcement::class)->shouldRemind($user))->toBeTrue();

    $this->flushSession();

    $this->actingAs($user)->get($dashboard)->assertRedirect(novaTwoFactorUrl('required'));
});

it('refuses to defer once the grace window has closed', function (): void {
    // Past the deadline there is nothing left to defer: the page is a wall, and
    // an endpoint that waves it away would be the way around enforcement.
    config()->set('nova-two-factor.enforcement.mode', EnforcementMode::Required->value);
    config()->set('nova-two-factor.enforcement.grace_days', 0);

    $this->actingAs(User::factory()->create(['created_at' => now()->subYears(1)]))
        ->post(novaTwoFactorUrl('required/remind-later'))
        ->assertForbidden();
});

it('lets a user in grace defer for the session, but never for days', function (): void {
    config()->set('nova-two-factor.enforcement.mode', EnforcementMode::Required->value);
    config()->set('nova-two-factor.enforcement.grace_days', 30);

    $user = User::factory()->create(['created_at' => now()->subDays(2)]);
    $enforcement = app(Enforcement::class);

    // Even asked for explicitly, the multi-day snooze is not on offer here: a
    // countdown that can be silenced past its own deadline is not a countdown.
    $this->actingAs($user)
        ->post(novaTwoFactorUrl('required/remind-later'), ['snooze' => '1'])
        ->assertRedirect();

    expect($enforcement->isSnoozed($user))->toBeFalse();
});

it('warns a user who still has time, instead of waiting for the wall', function (): void {
    // The gap this closes: under `required` with a grace window, nothing was
    // shown until the day the wall appeared, so the first a user heard of the
    // policy was being locked out by it.
    config()->set('nova-two-factor.enforcement.mode', EnforcementMode::Required->value);
    config()->set('nova-two-factor.enforcement.grace_days', 30);

    Route::middleware(['web', RequireTwoFactorEnrollment::class])->get('/grace-probe', fn (): string => 'through');

    $user = User::factory()->create(['created_at' => now()->subDays(2)]);

    $this->actingAs($user)->get('/grace-probe')->assertRedirect(novaTwoFactorUrl('required'));

    // And it can be put away for the session, rather than blocking work.
    $this->actingAs($user)->post(novaTwoFactorUrl('required/remind-later'))->assertRedirect();
    $this->actingAs($user)->get('/grace-probe')->assertOk();
});

/** Silence is asked for, never assumed: the box starts unticked. */
it('does not pre-tick the reminder checkbox', function (): void {
    config()->set('nova-two-factor.enforcement.mode', EnforcementMode::Encouraged->value);

    $html = $this->actingAs(User::factory()->create())
        ->get(novaTwoFactorUrl('required'))
        ->assertOk()
        ->getContent();

    expect($html)->toContain('name="snooze"')
        ->and(substr($html, (int) strpos($html, 'name="snooze"'), 120))->not->toContain('checked');
});

/**
 * A reset that leaves last week's "don't remind me" in place hands back an
 * account with nothing enrolled and nothing asking.
 */
it('starts prompting again after an administrative reset', function (): void {
    config()->set('nova-two-factor.enforcement.mode', EnforcementMode::Encouraged->value);

    $user = User::factory()->create();
    $enforcement = app(Enforcement::class);

    $enforcement->snooze($user);

    expect($enforcement->shouldRemind($user))->toBeFalse();

    app(Gabrielesbaiz\NovaTwoFactor\Actions\ResetTwoFactor::class)($user, 'test');

    expect($enforcement->shouldRemind($user))->toBeTrue();
});
