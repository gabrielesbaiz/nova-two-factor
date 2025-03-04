<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Tool API Routes
|--------------------------------------------------------------------------
|
| Here is where you may register API routes for your tool. These routes
| are loaded by the ServiceProvider of your tool. They are protected
| by your tool's "Authorize" middleware by default. Now, go build!
|
 */

Route::get('register', [Gabrielesbaiz\NovaTwoFactor\Http\Controller\TwoFactorController::class, 'registerUser']);

Route::match(['get', 'post'], '/recover', [Gabrielesbaiz\NovaTwoFactor\Http\Controller\TwoFactorController::class, 'recover'])->name('nova-two-factor.recover');

Route::post('confirm', [Gabrielesbaiz\NovaTwoFactor\Http\Controller\TwoFactorController::class, 'verifyOtp']);

Route::post('toggle', [Gabrielesbaiz\NovaTwoFactor\Http\Controller\TwoFactorController::class, 'toggle2Fa']);

Route::post('authenticate', [Gabrielesbaiz\NovaTwoFactor\Http\Controller\TwoFactorController::class, 'authenticate'])->name('nova-two-factor.auth');

Route::post('validatePrompt', [Gabrielesbaiz\NovaTwoFactor\Http\Controller\TwoFactorController::class, 'validatePrompt']);

Route::post('clear', [Gabrielesbaiz\NovaTwoFactor\Http\Controller\TwoFactorController::class, 'clear']);

Route::view('auth-otp', 'nova-two-factor::sign-in')->name('nova-two-factor.auth-form');
