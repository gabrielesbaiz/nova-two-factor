<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Http\Middleware\RequireTwoFactor;
use Gabrielesbaiz\NovaTwoFactor\Http\Middleware\RequireTwoFactorEnrollment;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable as FortifyAction;

/**
 * "Correctly configured" includes the published copies under `public/`, and
 * those are a real directory inside testbench that survives between runs. A
 * build that changes `dist/` leaves them stale, and the doctor then reports a
 * genuine problem — correct behaviour, and a test that fails for a reason
 * unrelated to what it is asserting. So the copies are made to match first.
 */
beforeEach(function (): void {
    foreach (['js/challenge.js', 'css/tool.css'] as $path) {
        $source = __DIR__.'/../../dist/'.$path;
        $published = public_path('vendor/nova-two-factor/'.$path);

        if (! file_exists($source)) {
            continue;
        }

        File::ensureDirectoryExists(dirname($published));
        File::copy($source, $published);
    }
});

it('passes on a correctly configured application', function (): void {
    $this->artisan('nova-two-factor:doctor')->assertSuccessful();
});

it('fails when the enforcement middleware is missing', function (): void {
    // The single most common 1.x install problem, and it produced no error at
    // all: `mandatory => true` with the middleware never registered.
    //
    // Stripped from the router group rather than from config('nova.middleware'):
    // the router is what actually runs, and a config array Nova has already
    // compiled and stopped reading is exactly the false PASS this check exists
    // to prevent.
    $router = app('router');

    $router->middlewareGroup('nova', array_values(array_diff(
        $router->getMiddlewareGroups()['nova'] ?? [],
        [RequireTwoFactor::class, RequireTwoFactorEnrollment::class],
    )));

    $this->artisan('nova-two-factor:doctor')->assertFailed();
});

it('fails when the guards are in config but never reached the router', function (): void {
    // The shape a real install produced: Nova compiles nova.middleware into its
    // router groups during its own boot, so a package that only edits the config
    // afterwards leaves Nova unguarded while the config reads as correct.
    $router = app('router');

    $router->middlewareGroup('nova', array_values(array_diff(
        $router->getMiddlewareGroups()['nova'] ?? [],
        [RequireTwoFactor::class, RequireTwoFactorEnrollment::class],
    )));

    config()->set('nova.middleware', [RequireTwoFactor::class, RequireTwoFactorEnrollment::class]);

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

it('reports nothing to check when the package is disabled', function (): void {
    // Several Nova panels from one codebase is a normal shape, and a domain
    // that turns the package off must not fail a deploy pipeline running
    // `doctor`.
    config()->set('nova-two-factor.enabled', false);

    $this->artisan('nova-two-factor:doctor')->assertSuccessful();
});

it('prunes expired records', function (): void {
    $this->artisan('nova-two-factor:prune')->assertSuccessful();
});

it('reports nothing to migrate when there is no legacy table', function (): void {
    $this->artisan('nova-two-factor:upgrade')->assertSuccessful();
});

/**
 * The 403 that sent us here: an app with every Nova Fortify feature off never
 * routes /user-security, so the security card has no page and the enforcement
 * screen's links dead-end.
 */
it('fails when Nova never routes its user-security page', function (): void {
    Route::getRoutes()->refreshNameLookups();

    $named = collect(Route::getRoutes()->getRoutesByName())
        ->reject(static fn ($route, string $name): bool => $name === 'nova.pages.user-security');

    $reflection = new ReflectionProperty(Illuminate\Routing\RouteCollection::class, 'nameList');
    $reflection->setValue(Route::getRoutes(), $named->all());

    $this->artisan('nova-two-factor:doctor')
        ->expectsOutputToContain('Nova user-security page')
        ->assertExitCode(1);
});

/**
 * The failure mode that cost an afternoon: the package is fixed, the browser is
 * served the copy in public/, and nothing anywhere says they differ.
 */
it('fails when the published pre-auth bundle is stale', function (): void {
    $published = public_path('vendor/nova-two-factor/js/challenge.js');

    @mkdir(dirname($published), 0777, true);
    file_put_contents($published, '// an older build');

    try {
        $this->artisan('nova-two-factor:doctor')
            ->expectsOutputToContain('Published asset js/challenge.js')
            ->assertExitCode(1);
    } finally {
        @unlink($published);
    }
});

/** The break-glass path has to work from a script, not just from a keyboard. */
it('resets without a prompt when forced', function (): void {
    $user = Workbench\App\Models\User::factory()->create();

    $this->artisan('nova-two-factor:reset', ['user' => $user->email, '--force' => true])
        ->doesntExpectOutputToContain('Continue?')
        ->assertExitCode(0);
});

it('warns when no admin gate guards the compliance figures', function (): void {
    config()->set('nova-two-factor.nova.admin_gate', null);
    config()->set('nova-two-factor.settings.editable', false);

    $this->artisan('nova-two-factor:doctor')
        ->expectsOutputToContain('No nova.admin_gate')
        ->assertSuccessful();
});

it('fails when the settings page is editable with no admin gate', function (): void {
    // Any user who can reach Nova could otherwise change enforcement mode or
    // pause it outright — the password prompt on those routes only proves they
    // are who they already are.
    config()->set('nova-two-factor.nova.admin_gate', null);
    config()->set('nova-two-factor.settings.editable', true);

    $this->artisan('nova-two-factor:doctor')
        ->expectsOutputToContain('any user who can reach Nova')
        ->assertFailed();
});

it('passes once a gate is named and defined', function (): void {
    config()->set('nova-two-factor.nova.admin_gate', 'manage-two-factor');
    config()->set('nova-two-factor.settings.editable', true);

    Gate::define('manage-two-factor', fn (): bool => true);

    $this->artisan('nova-two-factor:doctor')
        ->expectsOutputToContain('gated on [manage-two-factor]')
        ->assertSuccessful();
});

it('lists the patterns enforcement is told to leave alone', function (): void {
    // There is otherwise no way to see this list: it is merged at runtime from
    // the built-ins and `nova.path`, and a count is not seeing it.
    config()->set('nova-two-factor.enforcement.except', ['nova/custom-report']);

    $this->artisan('nova-two-factor:doctor')
        ->expectsOutputToContain('Enforcement leaves these open')
        ->expectsOutputToContain('nova/custom-report')
        ->assertSuccessful();
});

it('fails on a pattern that matches everything', function (): void {
    config()->set('nova-two-factor.enforcement.except', ['*']);

    $this->artisan('nova-two-factor:doctor')
        ->expectsOutputToContain('unguarded')
        ->assertFailed();
});

it('fails on a pattern that opens the Nova API', function (): void {
    // Guarding the pages while leaving the API open is exactly how 1.x could
    // be sidestepped — the data is behind `nova-api`, not behind the pages.
    config()->set('nova-two-factor.enforcement.except', ['nova-api/*']);

    $this->artisan('nova-two-factor:doctor')
        ->expectsOutputToContain('the Nova API')
        ->assertFailed();
});

it('fails on a broad pattern that happens to swallow the dashboard', function (): void {
    // Judged by what it lets through, not by how it is spelled: nobody writing
    // `nova*` intends to disable enforcement, and it does.
    config()->set('nova-two-factor.enforcement.except', ['nova*']);

    $this->artisan('nova-two-factor:doctor')
        ->expectsOutputToContain('the dashboard')
        ->assertFailed();
});

it('accepts a narrow pattern that opens one page', function (): void {
    config()->set('nova-two-factor.enforcement.except', ['nova/status', 'nova/health/*']);

    $this->artisan('nova-two-factor:doctor')
        ->expectsOutputToContain('2 of them yours')
        ->assertSuccessful();
});
