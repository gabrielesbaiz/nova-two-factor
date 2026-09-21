<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Http\Controllers\ChallengeController;
use Gabrielesbaiz\NovaTwoFactor\Http\Controllers\ComplianceController;
use Gabrielesbaiz\NovaTwoFactor\Http\Controllers\EnforcementController;
use Gabrielesbaiz\NovaTwoFactor\Http\Controllers\MethodController;
use Gabrielesbaiz\NovaTwoFactor\Http\Controllers\RecoveryCodeController;
use Gabrielesbaiz\NovaTwoFactor\Http\Controllers\SettingsController;
use Gabrielesbaiz\NovaTwoFactor\Http\Controllers\StepUpController;
use Gabrielesbaiz\NovaTwoFactor\Http\Controllers\TrustedDeviceController;
use Gabrielesbaiz\NovaTwoFactor\Http\Middleware\RequireVerifiedSession;
use Gabrielesbaiz\NovaTwoFactor\Support\Routing;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Two-factor routes
|--------------------------------------------------------------------------
|
| Registered under Nova's own path and inside Nova's authenticated middleware
| group, so they inherit its session, guard and CSRF handling. 1.x registered
| its endpoints under `nova-vendor/*` with a middleware stack that contained no
| authentication at all, leaving every one of them reachable by a guest.
|
| Note the deliberate absence of the challenge and enforcement pages from any
| step-up or challenge guard: locking someone out of the screen that unlocks
| them is how a 2FA gate becomes an outage.
|
*/

Route::prefix(Routing::prefix())->name('nova-two-factor.')->group(function (): void {
    // ---------------------------------------------------------------------
    // Challenge. Reachable while the session is authenticated but unverified.
    // ---------------------------------------------------------------------
    Route::get('challenge', [ChallengeController::class, 'show'])->name('challenge');
    Route::post('challenge/prepare', [ChallengeController::class, 'prepare'])->name('challenge.prepare');

    Route::post('challenge', [ChallengeController::class, 'store'])
        ->middleware('throttle:nova-two-factor:challenge')
        ->name('challenge.store');

    // ---------------------------------------------------------------------
    // Mandatory-enrollment interstitial.
    // ---------------------------------------------------------------------
    Route::get('required', [EnforcementController::class, 'show'])->name('required');

    // `encouraged` only, and enforced as such in the controller: putting the
    // reminder away must never be a way past a mode that blocks.
    Route::post('required/remind-later', [EnforcementController::class, 'remindLater'])
        ->name('required.remind-later');

    // ---------------------------------------------------------------------
    // Step-up. Its own limiter, separate from the login challenge, so
    // re-authorising an action cannot consume a user's login budget.
    // ---------------------------------------------------------------------
    Route::get('step-up', [StepUpController::class, 'show'])->name('step-up');
    Route::post('step-up/prepare', [StepUpController::class, 'prepare'])->name('step-up.prepare');

    Route::post('step-up', [StepUpController::class, 'store'])
        ->middleware('throttle:nova-two-factor:step-up')
        ->name('step-up.store');

    // ---------------------------------------------------------------------
    // Management.
    //
    // Reading is open to any authenticated session — it reports only the
    // viewer's own posture, and the security page has to render before anyone
    // can act on it.
    // ---------------------------------------------------------------------
    Route::get('methods', [MethodController::class, 'index'])->name('methods.index');

    // Changing a factor needs the factor. This prefix is exempt from the
    // challenge middleware by necessity — the challenge screen lives here — so
    // without this guard a stolen password could enroll a new factor, confirm
    // it, and be waved through as verified, which is the whole control
    // defeated in two requests. An account with nothing enrolled yet passes
    // through: that is the bootstrap case, and the only one.
    Route::middleware(RequireVerifiedSession::class)->group(function (): void {
        Route::middleware('throttle:nova-two-factor:enroll')->group(function (): void {
            Route::post('methods', [MethodController::class, 'store'])->name('methods.store');
            Route::post('methods/confirm', [MethodController::class, 'confirm'])->name('methods.confirm');
        });

        Route::patch('methods/{method}', [MethodController::class, 'rename'])->name('methods.rename');
        Route::put('methods/{method}/default', [MethodController::class, 'setDefault'])->name('methods.default');
    });

    // ---------------------------------------------------------------------
    // Admin compliance. Read-only, and gated inside the controller by
    // `nova.admin_gate` — the dashboard hides its menu entry, but a URL typed
    // by hand reaches the data behind it unless the endpoint checks too.
    // ---------------------------------------------------------------------
    Route::get('compliance', [ComplianceController::class, 'index'])->name('compliance');

    // No password prompt: one reminder is an email somebody ignores. A limiter
    // all the same, because ten thousand of them is your mail domain's
    // reputation — and the recipient-side cooldown lives in
    // `EnrollmentReminders`, where the bulk Nova action passes too.
    Route::post('compliance/remind', [ComplianceController::class, 'remind'])
        ->middleware('throttle:nova-two-factor:remind')
        ->name('compliance.remind');

    // Reading the policy is gated on the admin gate alone; every write below
    // additionally needs `settings.editable` and a confirmed password.
    Route::get('settings', [SettingsController::class, 'index'])->name('settings');

    // ---------------------------------------------------------------------
    // Destructive. Password confirmation is applied here, as middleware, so no
    // controller can forget it. 1.x let a single unauthenticated POST disable
    // an account's second factor outright.
    //
    // The class, not the `password.confirm` alias: aliases belong to the host
    // application, and an app on the Kernel layout that never registered that
    // one answered every destructive route with "Target class
    // [password.confirm] does not exist."
    //
    // The route name is passed explicitly for a second reason: Nova calls
    // `Fortify::ignoreRoutes()`, so the alias's default target resolves a route
    // that does not exist in a Nova-only application.
    // ---------------------------------------------------------------------
    // A password confirmation proves the first factor a second time; it says
    // nothing about the second. Both are required here, because an attacker
    // holding the password is precisely the threat these routes defend against
    // — regenerating recovery codes hands out working challenge answers, and
    // resetting somebody's factors removes theirs.
    Route::middleware([
        RequireVerifiedSession::class,
        RequirePassword::class.':nova.password.confirm',
    ])->group(function (): void {
        Route::delete('methods/{method}', [MethodController::class, 'destroy'])->name('methods.destroy');

        // Wiping someone else's second factor belongs in here with the rest of
        // the destructive routes — the typed address and the written reason are
        // checked in the controller, the password by this middleware.
        Route::post('compliance/reset', [ComplianceController::class, 'reset'])->name('compliance.reset');

        // Changing the rules that protect every account, and standing them down
        // entirely, both belong behind a fresh password — they are the two most
        // valuable things an attacker who reached an admin session could do.
        Route::patch('settings', [SettingsController::class, 'update'])->name('settings.update');
        Route::post('settings/pause', [SettingsController::class, 'pause'])->name('settings.pause');
        Route::post('settings/resume', [SettingsController::class, 'resume'])->name('settings.resume');

        Route::get('recovery-codes', [RecoveryCodeController::class, 'index'])->name('recovery-codes.index');
        Route::post('recovery-codes', [RecoveryCodeController::class, 'store'])->name('recovery-codes.store');

        Route::get('devices', [TrustedDeviceController::class, 'index'])->name('devices.index');
        Route::delete('devices/{device}', [TrustedDeviceController::class, 'destroy'])->name('devices.destroy');
        Route::delete('devices', [TrustedDeviceController::class, 'destroyAll'])->name('devices.destroy-all');
    });
});
