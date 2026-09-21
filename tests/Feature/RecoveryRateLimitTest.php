<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Recovery\RecoveryCodeManager;
use Gabrielesbaiz\NovaTwoFactor\Support\TwoFactorSession;
use Illuminate\Support\Facades\RateLimiter;
use Workbench\App\Models\User;

/**
 * The path somebody reaches *because* their usual factor is gone. Its budget
 * has to survive a mistyped transcription, and still stop a script.
 */
function recoveryUser(): User
{
    $user = User::factory()->create();

    $user->twoFactorMethods()->create([
        'type' => MethodType::Totp,
        'name' => 'Authenticator app',
        'secret' => 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',
        'confirmed_at' => now(),
    ]);

    return $user;
}

function submitRecovery(User $user, string $code)
{
    return test()->actingAs($user)->postJson('/nova/two-factor/challenge', [
        'recovery_code' => $code,
    ]);
}

it('recognises the shape of a code without judging its contents', function (): void {
    $codes = app(RecoveryCodeManager::class);

    expect($codes->looksWellFormed('abcdefghij-klmnopqrst'))->toBeTrue()
        // Formatting is incidental: what counts is twenty characters.
        ->and($codes->looksWellFormed('abcdefghijklmnopqrst'))->toBeTrue()
        ->and($codes->looksWellFormed('nope'))->toBeFalse()
        ->and($codes->looksWellFormed(str_repeat('x', 60)))->toBeFalse();
});

it('gives a mistyped transcription room to try again', function (): void {
    // Three tries used to be the whole budget — and the person spending it has
    // just lost their phone. Ten well-formed attempts is still nowhere near
    // guessing 119 bits.
    $user = recoveryUser();

    foreach (range(1, 9) as $attempt) {
        submitRecovery($user, 'abcdefghij-klmnopqrs'.$attempt)->assertStatus(422);
    }

    // Still the wrong-code answer, not the locked-out one.
    submitRecovery($user, 'abcdefghij-klmnopqrsX')->assertStatus(422);
});

it('stops a script pushing arbitrary bytes almost immediately', function (): void {
    $user = recoveryUser();

    submitRecovery($user, 'junk')->assertStatus(422);
    submitRecovery($user, 'more junk')->assertStatus(422);
    submitRecovery($user, 'still junk')->assertStatus(422);

    // The malformed budget is spent well before the well-formed one.
    submitRecovery($user, 'junk again')->assertStatus(429);
});

it('locks out for a flat window rather than an escalating one', function (): void {
    // The wait must not double each time: a break-glass path that ends in a
    // twelve-hour lock is one that ends in a phone call to an administrator,
    // which is a weaker check than the code would have been.
    config()->set('nova-two-factor.rate_limits.recovery.per_user', 2);
    config()->set('nova-two-factor.rate_limits.recovery.lockout', 600);

    $user = recoveryUser();

    submitRecovery($user, 'abcdefghij-klmnopqrst')->assertStatus(422);
    submitRecovery($user, 'abcdefghij-klmnopqrsu')->assertStatus(422);

    $first = submitRecovery($user, 'abcdefghij-klmnopqrsv');
    $first->assertStatus(429);

    $firstWait = $first->json('errors.recovery_code.0');

    // Another attempt against the closed door does not extend it.
    $second = submitRecovery($user, 'abcdefghij-klmnopqrsw');
    $second->assertStatus(429);

    expect($second->json('errors.recovery_code.0'))->toBe($firstWait);
});

it('does not spend the code budget on a recovery attempt', function (): void {
    // The two paths are separate: failing at recovery must not lock somebody
    // out of the authenticator they may still be able to reach.
    $user = recoveryUser();

    foreach (range(1, 3) as $attempt) {
        submitRecovery($user, 'junk '.$attempt)->assertStatus(422);
    }

    $key = 'nova-two-factor:challenge|'.$user->getMorphClass().'|'.$user->getAuthIdentifier().'|unknown';

    expect(RateLimiter::attempts($key))->toBe(0);
});

it('clears the budget once a code works', function (): void {
    $user = recoveryUser();
    $codes = app(RecoveryCodeManager::class)->regenerate($user);

    submitRecovery($user, 'junk')->assertStatus(422);

    $response = submitRecovery($user, (string) $codes->first());

    $response->assertOk();

    expect((new TwoFactorSession(session()->driver()))->hasPassed($user))->toBeTrue();

    foreach (['nova-two-factor:recovery|'.$user->getMorphClass().'|'.$user->getAuthIdentifier().'|unknown'] as $key) {
        expect(RateLimiter::attempts($key))->toBe(0);
    }
});
