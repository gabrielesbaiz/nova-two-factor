<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Drivers\EmailOtpDriver;
use Gabrielesbaiz\NovaTwoFactor\Enums\ChallengePurpose;
use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Exceptions\InvalidCodeException;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorChallenge;
use Gabrielesbaiz\NovaTwoFactor\Notifications\TwoFactorCodeNotification;
use Gabrielesbaiz\NovaTwoFactor\Results\VerificationResult;
use Gabrielesbaiz\NovaTwoFactor\TwoFactorManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Workbench\App\Models\User;

beforeEach(function (): void {
    Notification::fake();

    $this->user = User::factory()->create(['email' => 'gabriele@example.test']);
    $this->driver = app(EmailOtpDriver::class);
});

/** Read the plaintext code back out of the mail we just faked. */
function sentCode(): string
{
    $sent = null;

    Notification::assertSentOnDemand(
        TwoFactorCodeNotification::class,
        function (TwoFactorCodeNotification $notification) use (&$sent): bool {
            $reflection = new ReflectionProperty($notification, 'code');
            $sent = $reflection->getValue($notification);

            return true;
        },
    );

    return (string) $sent;
}

it('masks the destination and never returns the address', function (): void {
    $intent = $this->driver->beginEnrollment($this->user);

    // The challenge screen must not be usable to enumerate somebody's address,
    // and a fixed-width mask does not disclose the local part's length either.
    expect($intent->destinationHint)->toBe('g****@example.test')
        ->and($intent->toArray())->not->toHaveKey('destination');
});

it('leaves the method unconfirmed until the destination is verified', function (): void {
    $this->driver->beginEnrollment($this->user);

    // Otherwise "add email 2FA pointing at attacker@example.com" is a complete
    // account-takeover primitive.
    expect($this->user->hasTwoFactorEnabled())->toBeFalse()
        ->and($this->user->twoFactorMethods()->pending()->count())->toBe(1);
});

it('confirms the method once the emailed code is returned', function (): void {
    $this->driver->beginEnrollment($this->user);

    $method = $this->driver->completeEnrollment($this->user, ['code' => sentCode()]);

    expect($method->type)->toBe(MethodType::Email)
        ->and($method->isConfirmed())->toBeTrue()
        ->and($this->user->refresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('stores the code as an irreversible keyed digest', function (): void {
    $this->driver->beginEnrollment($this->user);
    $code = sentCode();

    $stored = DB::table(config('nova-two-factor.database.tables.challenges'))->value('code_hash');

    // A bare digest of a six-digit code is brute-forced instantly, so the stored
    // value is an HMAC keyed on the application key.
    expect($stored)->not->toBe($code)
        ->and($stored)->not->toBe(hash('sha256', $code))
        ->and($stored)->toHaveLength(64);
});

it('encrypts the destination at rest', function (): void {
    $this->driver->beginEnrollment($this->user);

    $stored = DB::table(config('nova-two-factor.database.tables.methods'))->value('destination');

    expect($stored)->not->toContain('gabriele@example.test');
});

it('refuses a wrong code', function (): void {
    $this->driver->beginEnrollment($this->user);

    expect(fn () => $this->driver->completeEnrollment($this->user, ['code' => '000000']))
        ->toThrow(InvalidCodeException::class);
});

it('refuses the same code twice', function (): void {
    $this->driver->beginEnrollment($this->user);
    $code = sentCode();

    $method = $this->driver->completeEnrollment($this->user, ['code' => $code]);

    $manager = app(TwoFactorManager::class);
    $result = $manager->verify(
        $method,
        ['code' => $code],
        $manager->context($this->user, ChallengePurpose::Login),
    );

    expect($result->passed)->toBeFalse();
});

it('invalidates the previous code when a new one is sent', function (): void {
    $this->driver->beginEnrollment($this->user);
    $first = sentCode();

    // Get past the resend window, then issue a second code.
    $this->travel(config('nova-two-factor.methods.email.resend_after') + 5)->seconds();
    $this->driver->beginEnrollment($this->user);

    // Leaving several codes live multiplies the guess surface against a value
    // that only carries about twenty bits.
    expect(fn () => $this->driver->completeEnrollment($this->user, ['code' => $first]))
        ->toThrow(InvalidCodeException::class);
});

it('expires a code after its ttl', function (): void {
    $this->driver->beginEnrollment($this->user);
    $code = sentCode();

    $this->travel(config('nova-two-factor.methods.email.ttl') + 5)->seconds();

    expect(fn () => $this->driver->completeEnrollment($this->user, ['code' => $code]))
        ->toThrow(InvalidCodeException::class);
});

it('burns the challenge once the attempt budget is spent', function (): void {
    $this->driver->beginEnrollment($this->user);
    $code = sentCode();

    $max = config('nova-two-factor.methods.email.max_attempts');

    for ($i = 0; $i < $max; $i++) {
        try {
            $this->driver->completeEnrollment($this->user, ['code' => '000000']);
        } catch (InvalidCodeException) {
            // expected
        }
    }

    // The correct code must now fail too. Otherwise a user simply requests a
    // new code and keeps their old guess allowance, turning a five-attempt
    // limit into an unlimited one.
    expect(fn () => $this->driver->completeEnrollment($this->user, ['code' => $code]))
        ->toThrow(InvalidCodeException::class);

    expect(TwoFactorChallenge::query()->whereNull('consumed_at')->count())->toBe(0);
});

it('refuses to resend before the cooldown has elapsed', function (): void {
    $this->driver->beginEnrollment($this->user);
    $code = sentCode();
    $method = $this->driver->completeEnrollment($this->user, ['code' => $code]);

    $manager = app(TwoFactorManager::class);
    $context = $manager->context($this->user, ChallengePurpose::Login);

    // The first challenge sends immediately even though enrollment just sent a
    // code: that one was consumed, so this is a first request rather than an
    // impatient resend.
    $first = $this->driver->beginChallenge($method, $context);
    $second = $this->driver->beginChallenge($method, $context);

    // Enforced against `sent_at` server-side; the countdown in the UI is only a
    // courtesy and must never be the thing holding the line.
    expect($first['sent'])->toBeTrue()
        ->and($second['sent'])->toBeFalse()
        ->and($second['retry_after'])->toBeGreaterThan(0);
});

it('reports the masked destination when a challenge is issued', function (): void {
    $this->driver->beginEnrollment($this->user);
    $method = $this->driver->completeEnrollment($this->user, ['code' => sentCode()]);

    $manager = app(TwoFactorManager::class);
    $payload = $this->driver->beginChallenge($method, $manager->context($this->user, ChallengePurpose::Login));

    expect($payload['destination_hint'])->toBe('g****@example.test')
        ->and(json_encode($payload))->not->toContain('gabriele@example.test');
});

it('never puts the code in the database notification payload', function (): void {
    $this->driver->beginEnrollment($this->user);
    $code = sentCode();

    $notification = new TwoFactorCodeNotification($code, ChallengePurpose::Login, 300);

    expect(json_encode($notification->toArray($this->user)))->not->toContain($code);
});

it('rejects a code that does not belong to the method', function (): void {
    $this->driver->beginEnrollment($this->user);
    $method = $this->driver->completeEnrollment($this->user, ['code' => sentCode()]);

    $other = User::factory()->create(['email' => 'other@example.test']);
    $this->driver->beginEnrollment($other);
    $otherCode = sentCode();

    $manager = app(TwoFactorManager::class);
    $result = $manager->verify(
        $method,
        ['code' => $otherCode],
        $manager->context($this->user, ChallengePurpose::Login),
    );

    expect($result->passed)->toBeFalse()
        ->and($result->failure)->toBe(VerificationResult::NO_METHOD);
});
