<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Enums\EnforcementMode;
use Illuminate\Support\Facades\Notification;
use Workbench\App\Models\User;

/**
 * Every server-rendered page, actually rendered.
 *
 * The suite reached these routes only as JSON POSTs, so `layout.blade.php` had
 * never been compiled by a test — and it called `Nova::$brandLogo`, a Nova 4
 * API. Reading an undeclared static property is a fatal `Error` in PHP, so the
 * challenge, step-up and enforcement screens all died on first render in a real
 * install while the suite stayed green.
 */
beforeEach(function (): void {
    Notification::fake();

    $this->user = User::factory()->create();
});

function novaPageUrl(string $path): string
{
    $prefix = trim((string) config('nova.path'), '/');
    $segment = trim((string) config('nova-two-factor.routes.prefix', 'two-factor'), '/');

    return '/'.trim($prefix.'/'.$segment.'/'.ltrim($path, '/'), '/');
}

it('renders the challenge page', function (): void {
    $this->actingAs($this->user)
        ->get(novaPageUrl('challenge'))
        ->assertOk()
        ->assertSee('<!DOCTYPE html>', false);
});

it('renders the step-up page', function (): void {
    $this->actingAs($this->user)
        ->get(novaPageUrl('step-up').'?scope=users.destroy')
        ->assertOk();
});

it('renders the enforcement page to a user who owes enrollment', function (): void {
    config()->set('nova-two-factor.enforcement.mode', EnforcementMode::Required->value);

    $this->actingAs($this->user)
        ->get(novaPageUrl('required'))
        ->assertOk();
});

/**
 * The pre-auth screens must honour the theme the user picked in Nova, not just
 * the OS preference. Nova's own switcher writes `localStorage.novaTheme`; the
 * layout read a `nova.appearance` key that Nova has never written, so an
 * explicit light choice rendered dark on any machine set to dark.
 */
it('reads the same theme key Nova writes', function (): void {
    $response = $this->actingAs($this->user)
        ->get(novaPageUrl('challenge'))
        ->assertOk();

    $response->assertSee("localStorage.getItem('novaTheme')", false);
    $response->assertDontSee('nova.appearance', false);
});

/**
 * The pre-auth screens borrow Nova's palette through its CSS variables. The
 * layout used to emit `--colors-{key}`, but Nova's variables are
 * `--colors-primary-{key}`, so a configured brand colour never applied and the
 * buttons stayed stock sky while the dashboard was branded.
 */
it('emits Nova brand colours under the variable names Nova uses', function (): void {
    config()->set('nova.brand.colors', ['500' => '220, 38, 38']);

    $this->actingAs($this->user)
        ->get(novaPageUrl('challenge'))
        ->assertOk()
        ->assertSee('--colors-primary-500: 220, 38, 38', false);
});

it('renders the brand logo when Nova is configured with one', function (): void {
    $logo = sys_get_temp_dir().'/nova-two-factor-brand.svg';
    file_put_contents($logo, '<svg id="brand-under-test"></svg>');

    config()->set('nova.brand.logo', $logo);

    $this->actingAs($this->user)
        ->get(novaPageUrl('challenge'))
        ->assertOk()
        ->assertSee('brand-under-test', false);

    @unlink($logo);
});

it('falls back to the application name when no brand logo is configured', function (): void {
    config()->set('nova.brand.logo', null);
    config()->set('app.name', 'Acme Admin');

    $this->actingAs($this->user)
        ->get(novaPageUrl('challenge'))
        ->assertOk()
        ->assertSee('Acme Admin', false);
});

it('enrolls in place on the enforcement page rather than sending the user into Nova', function (): void {
    config()->set('nova-two-factor.enforcement.mode', EnforcementMode::Required->value);

    $response = $this->actingAs($this->user)
        ->get(novaPageUrl('required'))
        ->assertOk();

    // The endpoints the page drives itself with, and the panel it drives them
    // into. Without these the page can only hand the user to Nova's SPA — which
    // is the thing a user who owes enrollment may not be able to reach.
    $response->assertSee('data-n2f-enroll', false)
        ->assertSee('data-n2f-enroll-panel', false)
        ->assertSee(route('nova-two-factor.methods.store'), false)
        ->assertSee(route('nova-two-factor.methods.confirm'), false);

    // One start hook per offered method.
    foreach (['webauthn', 'totp', 'email'] as $type) {
        $response->assertSee('data-n2f-enroll-start="'.$type.'"', false);
    }
});

it('keeps the management page as a no-script fallback', function (): void {
    config()->set('nova-two-factor.enforcement.mode', EnforcementMode::Required->value);

    // The rows stay real links: with the script absent, the old path is the
    // only way to enrol, so intercepting must not mean replacing.
    $this->actingAs($this->user)
        ->get(novaPageUrl('required'))
        ->assertOk()
        ->assertSee('/user-security#add-webauthn', false);
});

/**
 * The same switch governs the challenge chooser: proving with a factor is the
 * same kind of decision as enrolling one.
 */
it('carries the trade-off into the challenge chooser', function (): void {
    $driver = app(Gabrielesbaiz\NovaTwoFactor\Drivers\TotpDriver::class);
    $intent = $driver->beginEnrollment($this->user);
    $driver->completeEnrollment($this->user, [
        'code' => (new PragmaRX\Google2FA\Google2FA)->getCurrentOtp((string) $intent->secret),
    ]);

    // The chooser lists *alternatives* to the factor on screen, so the TOTP
    // trade-off only appears there when something else is the default.
    app(Gabrielesbaiz\NovaTwoFactor\Recovery\RecoveryCodeManager::class)->regenerate($this->user);

    $email = app(Gabrielesbaiz\NovaTwoFactor\Drivers\EmailOtpDriver::class);
    $email->beginEnrollment($this->user);

    $emailCode = null;
    Notification::assertSentOnDemand(
        Gabrielesbaiz\NovaTwoFactor\Notifications\TwoFactorCodeNotification::class,
        function ($notification) use (&$emailCode): bool {
            $emailCode = (new ReflectionProperty($notification, 'code'))->getValue($notification);

            return true;
        },
    );

    $method = $email->completeEnrollment($this->user, ['code' => $emailCode]);
    app(Gabrielesbaiz\NovaTwoFactor\Actions\SetDefaultMethod::class)($this->user, $method);

    $this->actingAs($this->user)
        ->get(novaPageUrl('challenge'))
        ->assertOk()
        ->assertSee('Works offline.', false);

    config()->set('nova-two-factor.ui.show_method_tradeoffs', false);

    $this->actingAs($this->user)
        ->get(novaPageUrl('challenge'))
        ->assertOk()
        ->assertDontSee('Works offline.', false);
});

/**
 * The trade-off is the sentence that decides the choice, so it gets its own
 * line — and its own switch, for applications that would rather not put
 * security framing in front of end users.
 */
it('shows each factor\'s trade-off, and hides it on request', function (): void {
    config()->set('nova-two-factor.enforcement.mode', EnforcementMode::Required->value);

    $this->actingAs($this->user)
        ->get(novaPageUrl('required'))
        ->assertOk()
        ->assertSee('Cannot be phished.', false)
        ->assertSee('n2f-method-tradeoff', false);

    config()->set('nova-two-factor.ui.show_method_tradeoffs', false);

    $this->actingAs($this->user)
        ->get(novaPageUrl('required'))
        ->assertOk()
        ->assertSee('Fingerprint, face, or a security key.', false)
        ->assertDontSee('Cannot be phished.', false);
});

/**
 * The mail that never arrives is the commonest failure of an email factor, and
 * the enrollment screen used to offer no way out of it.
 */
it('offers a resend on the enrollment screen', function (): void {
    config()->set('nova-two-factor.enforcement.mode', EnforcementMode::Required->value);

    $this->actingAs($this->user)
        ->get(novaPageUrl('required'))
        ->assertOk()
        ->assertSee('data-n2f-enroll-resend', false)
        ->assertSee('Send another code', false)
        ->assertSee('You can ask for another code in :time', false)
        // Re-entering the screen must say the code already sent still works.
        ->assertSee('A code is already on its way to', false)
        ->assertSee('The one already in your inbox is still the one that works.', false);
});

/**
 * Log out is one of the actions these screens offer, so it belongs inside the
 * card with the others rather than floating underneath it.
 */
it('keeps the way out inside the card', function (): void {
    config()->set('nova-two-factor.enforcement.mode', EnforcementMode::Required->value);

    foreach (['required', 'challenge'] as $page) {
        $html = $this->actingAs($this->user)->get(novaPageUrl($page))->assertOk()->getContent();

        $card = strpos($html, 'rounded-lg');
        $logout = strpos($html, 'nova.logout') ?: strpos($html, '/logout');
        $footer = strpos($html, '<footer');

        expect($card)->toBeInt()->and($logout)->toBeInt()->toBeGreaterThan($card)
            ->and($logout)->toBeLessThan((int) $footer);
    }
});

/**
 * The page announces "enter the code we emailed you", so the code has to be on
 * its way by the time it says so. It used to be sent only when the user picked
 * a method from the chooser — which, on a cold load with email as the default,
 * nobody does.
 */
it('prepares the default factor as the challenge page loads', function (): void {
    $driver = app(Gabrielesbaiz\NovaTwoFactor\Drivers\EmailOtpDriver::class);
    $driver->beginEnrollment($this->user);

    $code = null;
    Notification::assertSentOnDemand(
        Gabrielesbaiz\NovaTwoFactor\Notifications\TwoFactorCodeNotification::class,
        function ($notification) use (&$code): bool {
            $code = (new ReflectionProperty($notification, 'code'))->getValue($notification);

            return true;
        },
    );

    $driver->completeEnrollment($this->user, ['code' => $code]);

    $html = $this->actingAs($this->user)->get(novaPageUrl('challenge'))->assertOk()->getContent();

    // The bundle prepares it client-side, so the page has to carry the pieces:
    // the endpoint, the method id, and the type it should prepare.
    expect($html)->toContain('data-prepare-url')
        ->and($html)->toContain('data-n2f-method-id')
        ->and($html)->toContain('data-method-type="email"');
});

/**
 * With JavaScript off nothing can POST the prepare endpoint for the user, and
 * the GET that renders the page must not send a code — a link prefetch would
 * spend it before they read the mail. The send becomes a form they submit.
 */
it('lets a user without JavaScript ask for the code', function (): void {
    $driver = app(Gabrielesbaiz\NovaTwoFactor\Drivers\EmailOtpDriver::class);
    $driver->beginEnrollment($this->user);

    $code = null;
    Notification::assertSentOnDemand(
        Gabrielesbaiz\NovaTwoFactor\Notifications\TwoFactorCodeNotification::class,
        function ($notification) use (&$code): bool {
            $code = (new ReflectionProperty($notification, 'code'))->getValue($notification);

            return true;
        },
    );

    $method = $driver->completeEnrollment($this->user, ['code' => $code]);

    $this->actingAs($this->user)
        ->get(novaPageUrl('challenge'))
        ->assertOk()
        ->assertSee('<noscript>', false)
        ->assertSee('Send the code', false);

    // The same endpoint the bundle uses, answering a plain form post.
    $this->actingAs($this->user)
        ->from(novaPageUrl('challenge'))
        ->post(novaPageUrl('challenge/prepare'), ['method_id' => $method->id])
        ->assertRedirect(novaPageUrl('challenge'))
        ->assertSessionHas('nova-two-factor.status');
});

/** The trusted-device window is configurable, so its label has to survive 1. */
it('pluralises the trusted-device window', function (): void {
    $driver = app(Gabrielesbaiz\NovaTwoFactor\Drivers\TotpDriver::class);
    $intent = $driver->beginEnrollment($this->user);
    $driver->completeEnrollment($this->user, [
        'code' => (new PragmaRX\Google2FA\Google2FA)->getCurrentOtp((string) $intent->secret),
    ]);

    config()->set('nova-two-factor.trusted_devices.days', 1);

    $this->actingAs($this->user)->get(novaPageUrl('challenge'))->assertOk()
        ->assertSee('for a day', false)
        ->assertDontSee('for 1 days', false);

    config()->set('nova-two-factor.trusted_devices.days', 14);

    $this->actingAs($this->user)->get(novaPageUrl('challenge'))->assertOk()
        ->assertSee('for 14 days', false);
});

/**
 * The login challenge needed the same way out of "it never arrived" that the
 * enrollment screen has.
 */
it('offers a resend on the challenge screen', function (): void {
    $driver = app(Gabrielesbaiz\NovaTwoFactor\Drivers\EmailOtpDriver::class);
    $driver->beginEnrollment($this->user);

    $code = null;
    Notification::assertSentOnDemand(
        Gabrielesbaiz\NovaTwoFactor\Notifications\TwoFactorCodeNotification::class,
        function ($notification) use (&$code): bool {
            $code = (new ReflectionProperty($notification, 'code'))->getValue($notification);

            return true;
        },
    );

    $driver->completeEnrollment($this->user, ['code' => $code]);

    $this->actingAs($this->user)
        ->get(novaPageUrl('challenge'))
        ->assertOk()
        ->assertSee('data-n2f-resend', false)
        ->assertSee('Send another code', false)
        ->assertSee('You can ask for another code in :time', false);
});

/**
 * "Use another method" has to mean it. With one factor enrolled the list used
 * to open onto the very method already on screen — and it offered recovery
 * codes the user had never been shown.
 */
it('offers only real alternatives to the factor on screen', function (): void {
    $totp = app(Gabrielesbaiz\NovaTwoFactor\Drivers\TotpDriver::class);
    $intent = $totp->beginEnrollment($this->user);
    $totp->completeEnrollment($this->user, [
        'code' => (new PragmaRX\Google2FA\Google2FA)->getCurrentOtp((string) $intent->secret),
    ]);

    // One factor, no codes: nothing to choose between.
    $this->user->twoFactorRecoveryCodes()->delete();

    $this->actingAs($this->user)->get(novaPageUrl('challenge'))->assertOk()
        ->assertDontSee('data-n2f-chooser-toggle', false)
        ->assertDontSee('n2f-chooser', false);

    // Codes only: the control names what it actually offers, and carries the
    // other label for the bundle to switch to once a factor is in use.
    app(Gabrielesbaiz\NovaTwoFactor\Recovery\RecoveryCodeManager::class)->regenerate($this->user);

    $html = $this->actingAs($this->user)->get(novaPageUrl('challenge'))->assertOk()->getContent();

    // One label, whatever the list happens to hold — a recovery code is
    // another method. Every factor is rendered and the bundle hides the one in
    // use, so the list can lead back as well as away.
    expect($html)->toContain('Use another method')
        ->toContain('data-method-type="totp"')
        ->not->toContain('Use a recovery code');
});

/** Recovery codes are shown once, at enrollment, or they may as well not exist. */
it('shows the recovery codes it issues with the first factor', function (): void {
    config()->set('nova-two-factor.enforcement.mode', EnforcementMode::Required->value);

    $this->actingAs($this->user)
        ->get(novaPageUrl('required'))
        ->assertOk()
        ->assertSee('data-n2f-enroll-codes', false)
        ->assertSee('Save your recovery codes', false)
        ->assertSee('This is the only time we can show them to you.', false);
});

/**
 * Picking a factor makes the page about that factor: its input sits where a
 * factor's input always sits, and the list that opened it closes.
 */
it('puts the recovery input where the code input is', function (): void {
    $totp = app(Gabrielesbaiz\NovaTwoFactor\Drivers\TotpDriver::class);
    $intent = $totp->beginEnrollment($this->user);
    $totp->completeEnrollment($this->user, [
        'code' => (new PragmaRX\Google2FA\Google2FA)->getCurrentOtp((string) $intent->secret),
    ]);

    app(Gabrielesbaiz\NovaTwoFactor\Recovery\RecoveryCodeManager::class)->regenerate($this->user);

    $html = $this->actingAs($this->user)->get(novaPageUrl('challenge'))->assertOk()->getContent();

    $recovery = strpos($html, 'data-n2f-recovery-field');
    $chooser = strpos($html, 'id="n2f-chooser"');
    $submit = strpos($html, 'data-n2f-submit');

    // Above the submit, and above the list it is reached from.
    expect($recovery)->toBeInt()->toBeLessThan((int) $submit)
        ->and($recovery)->toBeLessThan((int) $chooser);

    // And the heading has a title for it to switch to.
    expect($html)->toContain('data-heading-recovery_code');
});

/**
 * The ways *out* of a screen are one quiet ruled-off row, not a stack of links
 * competing with the action that finishes it.
 */
it('groups the exits into a single row', function (): void {
    config()->set('nova-two-factor.enforcement.mode', EnforcementMode::Required->value);

    $html = $this->actingAs($this->user)->get(novaPageUrl('required'))->assertOk()->getContent();

    expect($html)->toContain('data-n2f-page-actions')
        ->toContain('n2f-page-actions-row')
        // One logout form, referenced by the control rather than wrapping it.
        ->toContain('form="n2f-logout"')
        ->toContain('id="n2f-logout"');

    // Exactly one logout form on the page, referenced rather than repeated.
    expect(substr_count($html, 'id="n2f-logout"'))->toBe(1);
});

/**
 * Codes shown once with no way out of the browser are codes nobody keeps — the
 * user is left transcribing sixteen characters by hand, eight times.
 */
it('offers copy, download and print with the recovery codes', function (): void {
    config()->set('nova-two-factor.enforcement.mode', EnforcementMode::Required->value);

    $this->actingAs($this->user)
        ->get(novaPageUrl('required'))
        ->assertOk()
        ->assertSee('data-n2f-codes-copy', false)
        ->assertSee('data-n2f-codes-download', false)
        ->assertSee('data-n2f-codes-print', false)
        // The printed sheet names the application and the account it belongs to.
        ->assertSee('data-print-title', false)
        ->assertSee('data-account', false);
});

/**
 * A passkey is submitted by the ceremony, so the form button has nothing to
 * post while it is the active factor — two buttons, one of which does nothing,
 * is a choice the user should not have to make.
 */
it('hides the submit button when the passkey is the default factor', function (): void {
    $method = $this->user->twoFactorMethods()->create([
        'type' => Gabrielesbaiz\NovaTwoFactor\Enums\MethodType::WebAuthn,
        'name' => 'Passkey',
        'credential_id' => 'abc',
        'credential' => ['publicKey' => 'x'],
        'confirmed_at' => now(),
        'is_default' => true,
    ]);

    $html = $this->actingAs($this->user)->get(novaPageUrl('challenge'))->assertOk()->getContent();

    $submit = strpos($html, 'data-n2f-submit');
    $classes = substr($html, max(0, (int) $submit - 120), 160);

    expect($submit)->toBeInt()->and($classes)->toContain('hidden');

    // A typed factor keeps it.
    $method->forceFill(['is_default' => false])->save();
    [$totp] = enrolledTotp($this->user);
    $totp->forceFill(['is_default' => true])->save();

    $html = $this->actingAs($this->user)->get(novaPageUrl('challenge'))->assertOk()->getContent();
    $classes = substr($html, max(0, (int) strpos($html, 'data-n2f-submit') - 120), 160);

    expect($classes)->not->toContain('hidden');
});

/**
 * Tailwind's `.hidden` lives in Nova's stylesheet, loaded before this package's
 * — so every component class here that sets `display` beat it on source order,
 * and "hidden" elements kept rendering.
 */
it('makes hidden win over its own display rules', function (): void {
    $css = file_get_contents(__DIR__.'/../../dist/css/tool.css');

    foreach (['.n2f-btn', '.n2f-method-row', '.n2f-page-actions'] as $component) {
        $declaration = strpos($css, $component.'{');
        $override = strpos($css, $component.'.hidden');

        expect($override)->toBeInt()
            ->and($override)->toBeGreaterThan((int) $declaration);
    }
});
