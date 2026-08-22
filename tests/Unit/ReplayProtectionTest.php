<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Workbench\App\Models\User;

/**
 * 1.x had no replay protection at all: google2fa-laravel ships
 * `forbid_old_passwords => false`, and the package never overrode it, so a
 * captured code stayed valid for the full +/-1-step window.
 */
beforeEach(function (): void {
    $this->user = User::factory()->create();

    $this->method = new TwoFactorMethod([
        'type' => MethodType::Totp,
        'name' => 'Authenticator app',
        'secret' => 'JBSWY3DPEHPK3PXP',
        'confirmed_at' => now(),
    ]);

    $this->method->authenticatable()->associate($this->user);
    $this->method->save();
});

it('accepts a fresh timestep', function (): void {
    expect($this->method->claimTimestep(58_000_000))->toBeTrue();
    expect($this->method->fresh()->last_timestep)->toBe(58_000_000);
});

it('refuses the same timestep twice', function (): void {
    expect($this->method->claimTimestep(58_000_000))->toBeTrue();
    expect($this->method->claimTimestep(58_000_000))->toBeFalse();
});

it('refuses an older timestep still inside the drift window', function (): void {
    $this->method->claimTimestep(58_000_000);

    expect($this->method->claimTimestep(57_999_999))->toBeFalse()
        ->and($this->method->fresh()->last_timestep)->toBe(58_000_000);
});

it('lets exactly one of two concurrent claims win', function (): void {
    // Two independent model instances stand in for two requests racing the same
    // code. The claim is a conditional UPDATE, so the affected-row count decides
    // it without a lock or a transaction.
    $a = TwoFactorMethod::query()->findOrFail($this->method->getKey());
    $b = TwoFactorMethod::query()->findOrFail($this->method->getKey());

    $results = [$a->claimTimestep(58_000_123), $b->claimTimestep(58_000_123)];

    expect(array_filter($results))->toHaveCount(1);
});

it('records the time a claim succeeded', function (): void {
    expect($this->method->last_used_at)->toBeNull();

    $this->method->claimTimestep(58_000_000);

    expect($this->method->fresh()->last_used_at)->not->toBeNull();
});

it('does not treat a zero signature counter as a regression', function (): void {
    // Every synced passkey — iCloud Keychain, Google Password Manager — reports
    // a counter of zero forever. A naive `<=` check would reject the entire
    // platform, so zero-to-zero has to be allowed through.
    expect($this->method->claimSignCount(0))->toBeTrue()
        ->and($this->method->claimSignCount(0))->toBeTrue();
});

it('accepts an advancing signature counter and refuses a regression', function (): void {
    expect($this->method->claimSignCount(5))->toBeTrue()
        ->and($this->method->fresh()->sign_count)->toBe(5)
        ->and($this->method->claimSignCount(4))->toBeFalse()
        ->and($this->method->claimSignCount(5))->toBeFalse()
        ->and($this->method->claimSignCount(6))->toBeTrue();
});
