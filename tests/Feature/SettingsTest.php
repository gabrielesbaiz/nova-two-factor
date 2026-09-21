<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Enums\AuditEvent;
use Gabrielesbaiz\NovaTwoFactor\Http\Middleware\RequireTwoFactor;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorAudit;
use Gabrielesbaiz\NovaTwoFactor\Settings\Pause;
use Gabrielesbaiz\NovaTwoFactor\Settings\SettingSchema;
use Gabrielesbaiz\NovaTwoFactor\Settings\SettingsRepository;
use Illuminate\Support\Facades\Route;
use Workbench\App\Models\User;

function settingsUrl(string $path = ''): string
{
    return '/'.trim(trim((string) config('nova.path'), '/').'/two-factor/settings/'.ltrim($path, '/'), '/');
}

beforeEach(function (): void {
    config()->set('nova-two-factor.settings.editable', true);
    app(SettingsRepository::class)->flush();
});

/**
 * Every write here sits behind `RequirePassword`, which answers 423 rather than
 * running the controller. The browser satisfies it with Nova's modal; a test
 * satisfies it with the session key the middleware reads — and asserting the
 * guard itself is a separate test rather than a tax on every other one.
 */
function withConfirmedPassword(): void
{
    session(['auth.password_confirmed_at' => time()]);
}

it('overlays stored settings onto config', function (): void {
    app(SettingsRepository::class)->set('enforcement.grace_days', 21);

    // Applied the way every other read in the package sees it — through config,
    // so nothing else has to know the overlay exists.
    app(SettingsRepository::class)->apply();

    expect(config('nova-two-factor.enforcement.grace_days'))->toBe(21);
});

it('lets the panel override the environment, and says so', function (): void {
    // A decision made in the UI is the most recent statement of intent, so it
    // wins — but somebody will read `.env`, see the old value and believe it,
    // which is why the disagreement has to be visible rather than silent.
    $_ENV['NOVA_TWO_FACTOR_GRACE_DAYS'] = '3';
    config()->set('nova-two-factor.enforcement.grace_days', 3);

    app(SettingsRepository::class)->set('enforcement.grace_days', 21);
    app(SettingsRepository::class)->apply();

    $state = app(SettingsRepository::class)->state('enforcement.grace_days');

    expect(config('nova-two-factor.enforcement.grace_days'))->toBe(21)
        ->and($state['from_env'])->toBeTrue()
        ->and($state['overrides_env'])->toBeTrue()
        ->and($state['env_value'])->toBe(3);

    unset($_ENV['NOVA_TWO_FACTOR_GRACE_DAYS']);
});

it('goes back to the environment value when a setting is cleared', function (): void {
    // Cleared, not overwritten with the file's current value: a later deploy
    // that changes the variable should be followed again.
    $_ENV['NOVA_TWO_FACTOR_GRACE_DAYS'] = '3';
    config()->set('nova-two-factor.enforcement.grace_days', 3);

    $repository = app(SettingsRepository::class);
    $repository->set('enforcement.grace_days', 21);
    $repository->set('enforcement.grace_days', null);

    expect($repository->state('enforcement.grace_days')['stored'])->toBeFalse()
        ->and(config('nova-two-factor.enforcement.grace_days'))->toBe(3);

    unset($_ENV['NOVA_TWO_FACTOR_GRACE_DAYS']);
});

it('refuses to write a key that is not on the allow-list', function (): void {
    // `admin_gate` decides who may administer this. Editable from the page the
    // gate protects, it is privilege escalation with extra steps.
    withConfirmedPassword();

    $this->actingAs(User::factory()->create())
        ->patchJson(settingsUrl(), ['settings' => ['nova.admin_gate' => null]])
        ->assertStatus(422);
});

it('changes a mode the environment also sets', function (): void {
    // The case that drove this: a deployment ships a mode in `.env`, and being
    // able to change it without a deploy is the whole point of turning the
    // settings page on.
    $_ENV['NOVA_TWO_FACTOR_MODE'] = 'optional';
    config()->set('nova-two-factor.enforcement.mode', 'optional');
    withConfirmedPassword();

    $this->actingAs(User::factory()->create())
        ->patchJson(settingsUrl(), ['settings' => ['enforcement.mode' => 'required']])
        ->assertOk();

    expect(config('nova-two-factor.enforcement.mode'))->toBe('required');

    unset($_ENV['NOVA_TWO_FACTOR_MODE']);
});

it('refuses every write when the panel is not allowed to change settings', function (): void {
    config()->set('nova-two-factor.settings.editable', false);
    withConfirmedPassword();

    $admin = User::factory()->create();

    // Reading stays allowed: knowing the policy is useful where changing it is
    // not.
    $this->actingAs($admin)->getJson(settingsUrl())->assertOk();

    $this->actingAs($admin)
        ->patchJson(settingsUrl(), ['settings' => ['enforcement.mode' => 'required']])
        ->assertForbidden();
});

it('rejects a value outside the range the setting allows', function (): void {
    // A step-up window of a day is a valid integer and removes the protection
    // the setting exists to provide.
    withConfirmedPassword();

    $this->actingAs(User::factory()->create())
        ->patchJson(settingsUrl(), ['settings' => ['step_up.ttl' => 86400]])
        ->assertStatus(422);
});

it('audits a change with both values', function (): void {
    withConfirmedPassword();

    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->patchJson(settingsUrl(), ['settings' => ['enforcement.mode' => 'required']])
        ->assertOk();

    $audit = TwoFactorAudit::query()->where('event', AuditEvent::SettingChanged->value)->first();

    // "Changed to required" is half a sentence; the half that matters in an
    // incident is what it was before.
    expect($audit)->not->toBeNull()
        ->and($audit->context['key'])->toBe('enforcement.mode')
        ->and($audit->context['to'])->toBe('required')
        ->and($audit->context['by'])->toBe($admin->getKey());
});

it('reports what switching to required would cost', function (): void {
    User::factory()->count(3)->create();

    $response = $this->actingAs(User::factory()->create())->getJson(settingsUrl());

    // The sentence that has to appear before the save, not after it.
    expect($response->json('impact.without_factor'))->toBe(4)
        ->and($response->json('impact.in_scope'))->toBe(4);
});

it('stands enforcement down while paused, and brings it back', function (): void {
    $pause = app(Pause::class);

    expect($pause->active())->toBeFalse();

    $pause->start(30, 'migrating SSO');

    expect($pause->active())->toBeTrue()
        ->and($pause->state()['reason'])->toBe('migrating SSO');

    $pause->resume();

    expect($pause->active())->toBeFalse();
});

it('caps a pause at the configured ceiling', function (): void {
    // "Pause for a week" is how a control gets switched off and forgotten.
    config()->set('nova-two-factor.settings.pause_max_minutes', 30);

    $until = app(Pause::class)->start(10080, 'far too long');

    expect($until->diffInMinutes(now()))->toBeLessThanOrEqual(30);
});

it('lets a challenged user through while paused', function (): void {
    config()->set('nova-two-factor.enforcement.mode', 'required');

    $user = User::factory()->create();
    $user->twoFactorMethods()->create([
        'type' => 'totp',
        'name' => 'Authenticator',
        'secret' => 'x',
        'confirmed_at' => now(),
    ]);

    Route::middleware(['web', RequireTwoFactor::class])->get('/paused-probe', fn (): string => 'through');

    // Unverified session, so the guard would normally redirect to the challenge.
    $this->actingAs($user)->get('/paused-probe')->assertRedirect();

    app(Pause::class)->start(30, 'migrating SSO');

    $this->actingAs($user)->get('/paused-probe')->assertOk()->assertSee('through');
});

it('requires a reason to pause', function (): void {
    withConfirmedPassword();

    $this->actingAs(User::factory()->create())
        ->postJson(settingsUrl('pause'), ['minutes' => 30])
        ->assertStatus(422);
});

it('guards every write with password confirmation', function (): void {
    // Asserted once, here, rather than implied by the tests above: changing the
    // rules that protect every account, and standing them down entirely, are
    // the two most valuable things an attacker inside an admin session could do.
    $admin = User::factory()->create();

    $this->actingAs($admin)
        ->patchJson(settingsUrl(), ['settings' => ['enforcement.mode' => 'required']])
        ->assertStatus(423);

    $this->actingAs($admin)
        ->postJson(settingsUrl('pause'), ['minutes' => 30, 'reason' => 'migrating SSO'])
        ->assertStatus(423);

    $this->actingAs($admin)->postJson(settingsUrl('resume'))->assertStatus(423);
});

it('declares which modes each setting has any effect in', function (): void {
    // A grace window means nothing where nothing blocks, and a reminder
    // interval nothing where there is no reminder. Offering them anyway invites
    // configuring a number that does nothing — worse than not offering it,
    // because the administrator walks away believing they changed the policy.
    $schema = SettingSchema::all();

    expect($schema['enforcement.grace_days']['modes'])->toBe(['required'])
        ->and($schema['enforcement.enforced_from']['modes'])->toBe(['required'])
        ->and($schema['enforcement.remind_every_days']['modes'])->toBe(['encouraged'])
        ->and($schema['enforcement.mode'])->not->toHaveKey('modes')
        ->and($schema['methods.totp.enabled'])->not->toHaveKey('modes');
});

it('sends the mode dependency to the panel', function (): void {
    $response = $this->actingAs(User::factory()->create())->getJson(settingsUrl());

    $fields = collect($response->json('sections.enforcement'))->keyBy('key');

    expect($fields['enforcement.grace_days']['modes'])->toBe(['required'])
        ->and($fields['enforcement.mode']['modes'])->toBeNull();
});

it('declares which settings depend on another', function (): void {
    // How a grace window is measured is a question with no answer while grace
    // itself is off, and offering it anyway is what made the section read as
    // four unrelated fields.
    $schema = SettingSchema::all();

    expect($schema['enforcement.grace_mode']['requires'])
        ->toBe(['enforcement.grace_enabled' => true])
        ->and($schema['enforcement.grace_days']['requires'])
        ->toBe(['enforcement.grace_enabled' => true, 'enforcement.grace_mode' => 'days'])
        ->and($schema['enforcement.enforced_from']['requires'])
        ->toBe(['enforcement.grace_enabled' => true, 'enforcement.grace_mode' => 'date']);
});

it('sends the dependency to the panel', function (): void {
    $fields = collect(
        $this->actingAs(User::factory()->create())->getJson(settingsUrl())->json('sections.enforcement'),
    )->keyBy('key');

    expect($fields['enforcement.grace_days']['requires'])
        ->toBe(['enforcement.grace_enabled' => true, 'enforcement.grace_mode' => 'days'])
        ->and($fields['enforcement.mode']['requires'])->toBeNull();
});

it('refuses a save that would leave no method to enrol', function (): void {
    // Turning off the last one does not switch two-factor off: it leaves the
    // policy in force with nothing anyone can satisfy, which under `required`
    // locks every user out of Nova — including whoever clicked save.
    withConfirmedPassword();

    $this->actingAs(User::factory()->create())
        ->patchJson(settingsUrl(), ['settings' => [
            'methods.webauthn.enabled' => false,
            'methods.totp.enabled' => false,
            'methods.email.enabled' => false,
        ]])
        ->assertStatus(422);

    expect(config('nova-two-factor.methods.totp.enabled'))->toBeTrue();
});

it('allows turning one off while another stays on', function (): void {
    withConfirmedPassword();

    $this->actingAs(User::factory()->create())
        ->patchJson(settingsUrl(), ['settings' => [
            'methods.webauthn.enabled' => false,
            'methods.email.enabled' => false,
        ]])
        ->assertOk();

    expect(config('nova-two-factor.methods.totp.enabled'))->toBeTrue()
        ->and(config('nova-two-factor.methods.email.enabled'))->toBeFalse();
});

it('counts switches it was not sent as they currently stand', function (): void {
    // The three arrive together only when all three moved; a payload that
    // turns off two must still see the third.
    config()->set('nova-two-factor.methods.webauthn.enabled', false);
    config()->set('nova-two-factor.methods.totp.enabled', false);

    withConfirmedPassword();

    $this->actingAs(User::factory()->create())
        ->patchJson(settingsUrl(), ['settings' => ['methods.email.enabled' => false]])
        ->assertStatus(422);
});

it('hides the email code fields behind the email switch', function (): void {
    $fields = collect(
        $this->actingAs(User::factory()->create())->getJson(settingsUrl())->json('sections.methods'),
    )->keyBy('key');

    expect($fields['methods.email.ttl']['requires'])->toBe(['methods.email.enabled' => true])
        ->and($fields['methods.email.resend_after']['requires'])
        ->toBe(['methods.email.enabled' => true]);
});

it('restores defaults even when a method switch was turned off', function (): void {
    // The bug: clearing a key means "fall back to the default", and the
    // last-method guard read the null as "off" — so restoring defaults refused
    // itself with "at least one method has to stay switched on".
    $repository = app(SettingsRepository::class);
    $repository->set('methods.webauthn.enabled', false);
    $repository->set('methods.totp.enabled', false);
    $repository->set('methods.email.enabled', false);
    $repository->apply();

    withConfirmedPassword();

    $this->actingAs(User::factory()->create())
        ->patchJson(settingsUrl(), ['settings' => [
            'methods.webauthn.enabled' => null,
            'methods.totp.enabled' => null,
            'methods.email.enabled' => null,
        ]])
        ->assertOk();

    expect(config('nova-two-factor.methods.totp.enabled'))->toBeTrue()
        ->and($repository->state('methods.totp.enabled')['stored'])->toBeFalse();
});

it('still refuses to clear the last method when the default is off too', function (): void {
    // Not a special case for "clear": what matters is the state the save
    // produces, whichever way each value arrives at it.
    // The defaults are set first, so the baseline the overlay records is the
    // real one: an override captures what was underneath it at the moment it
    // was written.
    config()->set('nova-two-factor.methods.webauthn.enabled', false);
    config()->set('nova-two-factor.methods.email.enabled', false);
    config()->set('nova-two-factor.methods.totp.enabled', false);

    $repository = app(SettingsRepository::class);
    $repository->set('methods.totp.enabled', true);

    withConfirmedPassword();

    $this->actingAs(User::factory()->create())
        ->patchJson(settingsUrl(), ['settings' => ['methods.totp.enabled' => null]])
        ->assertStatus(422);
});
