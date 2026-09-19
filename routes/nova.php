<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Http\Controllers\ChallengeController;
use Gabrielesbaiz\NovaTwoFactor\Http\Controllers\EnforcementController;
use Gabrielesbaiz\NovaTwoFactor\Http\Controllers\MethodController;
use Gabrielesbaiz\NovaTwoFactor\Http\Controllers\RecoveryCodeController;
use Gabrielesbaiz\NovaTwoFactor\Http\Controllers\StepUpController;
use Gabrielesbaiz\NovaTwoFactor\Http\Controllers\TrustedDeviceController;
use Gabrielesbaiz\NovaTwoFactor\Support\Routing;
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
    // Management. Everything here needs a verified session.
    // ---------------------------------------------------------------------
    Route::get('methods', [MethodController::class, 'index'])->name('methods.index');

    Route::middleware('throttle:nova-two-factor:enroll')->group(function (): void {
        Route::post('methods', [MethodController::class, 'store'])->name('methods.store');
        Route::post('methods/confirm', [MethodController::class, 'confirm'])->name('methods.confirm');
    });

    Route::patch('methods/{method}', [MethodController::class, 'rename'])->name('methods.rename');
    Route::put('methods/{method}/default', [MethodController::class, 'setDefault'])->name('methods.default');

    // ---------------------------------------------------------------------
    // Destructive. Password confirmation is applied here, as middleware, so no
    // controller can forget it. 1.x let a single unauthenticated POST disable
    // an account's second factor outright.
    //
    // The route name is passed explicitly: Nova calls `Fortify::ignoreRoutes()`,
    // so the bare `password.confirm` alias resolves a route that does not exist
    // in a Nova-only application.
    // ---------------------------------------------------------------------
    Route::middleware('password.confirm:nova.password.confirm')->group(function (): void {
        Route::delete('methods/{method}', [MethodController::class, 'destroy'])->name('methods.destroy');

        Route::get('recovery-codes', [RecoveryCodeController::class, 'index'])->name('recovery-codes.index');
        Route::post('recovery-codes', [RecoveryCodeController::class, 'store'])->name('recovery-codes.store');

        Route::get('devices', [TrustedDeviceController::class, 'index'])->name('devices.index');
        Route::delete('devices/{device}', [TrustedDeviceController::class, 'destroy'])->name('devices.destroy');
        Route::delete('devices', [TrustedDeviceController::class, 'destroyAll'])->name('devices.destroy-all');
    });
});
