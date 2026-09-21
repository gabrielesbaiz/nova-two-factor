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

    // Get past the resend window, then deliberately ask for another.
    $this->travel(config('nova-two-factor.methods.email.resend_after') + 5)->seconds();
    $this->driver->beginEnrollment($this->user, ['resend' => true]);

    // Leaving several codes live multiplies the guess surface against a value
    // that only carries about twenty bits.
    expect(fn () => $this->driver->completeEnrollment($this->user, ['code' => $first]))
        ->toThrow(InvalidCodeException::class);
});

/**
 * Arriving on the screen again is not a request for a new code. Minting one
 * anyway kills the code already in the inbox, so the newest-looking mail holds
 * six digits that no longer work — which is exactly how a user ends up retyping
 * a dead code until the attempt budget is gone.
 */
it('reuses the code already sent when enrollment is re-entered', function (): void {
    $intent = $this->driver->beginEnrollment($this->user);
    $first = sentCode();

    Notification::fake();

    $again = $this->driver->beginEnrollment($this->user);

    Notification::assertNothingSent();

    expect($again->toArray()['sent'])->toBeFalse()
        ->and($again->toArray()['sent_at'])->toBe($intent->toArray()['sent_at'])
        ->and($again->toArray()['resend_after'])->toBeGreaterThan(0);

    // And the code in the inbox is still the one that works.
    expect($this->driver->completeEnrollment($this->user, ['code' => $first])->isConfirmed())->toBeTrue();
});

it('refuses to resend inside the cooldown, without burning the live code', function (): void {
    $this->driver->beginEnrollment($this->user);
    $first = sentCode();

    Notification::fake();

    $this->driver->beginEnrollment($this->user, ['resend' => true]);

    Notification::assertNothingSent();

    expect($this->driver->completeEnrollment($this->user, ['code' => $first])->isConfirmed())->toBeTrue();
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

/**
 * The compromise warning is addressed to a different reader than the rest of
 * the mail, and asks for action on a different system. Run on from the expiry
 * sentence it reads as housekeeping.
 */
it('sets the compromise warning apart from the instructions', function (): void {
    $html = (string) (new TwoFactorCodeNotification('147539', ChallengePurpose::Login, 300))
        ->toMail($this->user)
        ->render();

    $expiry = strpos($html, 'expires in');
    $warning = strpos($html, 'did not request this');

    expect($expiry)->toBeInt()->and($warning)->toBeInt()->toBeGreaterThan($expiry);

    // Its own block with air around it — never a rule, which in a short mail
    // reads as a footer, and never run on into the salutation.
    $between = substr($html, (int) $expiry, (int) $warning - (int) $expiry);

    expect($between)->toContain('n2f-mail-warning')->not->toContain('border-top');
});

/**
 * The code is the only reason the mail exists, so it is an object on the page
 * rather than a bold word mid-sentence — and still plain text, never a link.
 */
it('gives the code its own block in the mail', function (): void {
    $notification = new TwoFactorCodeNotification('147539', ChallengePurpose::Login, 300);

    $html = (string) $notification->toMail($this->user)->render();

    expect($html)
        ->toContain('147539')
        ->toContain('letter-spacing')
        // Never a clickable action: a button in a second-factor mail is a
        // ready-made phishing target, and it trains people to click one. (The
        // layout's own masthead link to the application is not one.)
        ->not->toContain('class="button')
        ->not->toContain('action-content');
});

/**
 * Sending a new code kills the previous one. A user reading the older mail
 * types six digits that were right when they were sent — and "that code is not
 * correct" sends them to retype it until the budget runs out.
 */
it('says so when the code typed was one we replaced', function (): void {
    $method = $this->driver->completeEnrollment($this->user, [
        'code' => (function () {
            $this->driver->beginEnrollment($this->user);

            return sentCode();
        })(),
    ]);

    $manager = app(Gabrielesbaiz\NovaTwoFactor\Otp\OtpCodeManager::class);

    [, $first] = $manager->issue($this->user, $method, ChallengePurpose::Login);
    [, $second] = $manager->issue($this->user, $method, ChallengePurpose::Login);

    expect($manager->verify($method, $first)->failure)->toBe(VerificationResult::SUPERSEDED)
        ->and($manager->verify($method, '000000')->failure)->toBe(VerificationResult::INVALID_CODE)
        ->and($manager->verify($method, $second)->passed)->toBeTrue();
});

/** Leading zeros survive the whole round trip — they are digits, not a number. */
it('accepts a code that starts with a zero', function (): void {
    $method = $this->driver->completeEnrollment($this->user, [
        'code' => (function () {
            $this->driver->beginEnrollment($this->user);

            return sentCode();
        })(),
    ]);

    $manager = app(Gabrielesbaiz\NovaTwoFactor\Otp\OtpCodeManager::class);
    [$challenge] = $manager->issue($this->user, $method, ChallengePurpose::Login);

    $challenge->forceFill(['code_hash' => (function () use ($manager) {
        $hash = new ReflectionMethod($manager, 'hash');

        return $hash->invoke($manager, '001298');
    })()])->save();

    expect($manager->verify($method, '001298')->passed)->toBeTrue();
});

/**
 * Re-enrolling replaces the pending method, so codes from the previous attempt
 * hang off a method row that no longer exists on screen — and those are exactly
 * the mails still sitting in the inbox. Scoped to the method id alone, they
 * came back as "that code is not correct", which is the same dead end again.
 */
it('recognises a superseded code issued to an earlier pending method', function (): void {
    $manager = app(Gabrielesbaiz\NovaTwoFactor\Otp\OtpCodeManager::class);

    $this->driver->beginEnrollment($this->user);
    $stale = sentCode();

    // Confirm it, so the next enrollment starts a second method rather than
    // reusing the pending one. (A *deleted* method takes its challenges with it
    // — cascade — and those codes are then undetectable by design.)
    $this->driver->completeEnrollment($this->user, ['code' => $stale]);

    $this->travel(config('nova-two-factor.methods.email.resend_after') + 5)->seconds();
    $this->driver->beginEnrollment($this->user);
    $fresh = sentCode();

    $method = $this->user->twoFactorMethods()->latest('id')->first();

    expect($method->is($this->user->twoFactorMethods()->pending()->latest('id')->first()))->toBeTrue()
        ->and($manager->verify($method, $stale)->failure)->toBe(VerificationResult::SUPERSEDED)
        ->and($manager->verify($method, $fresh)->passed)->toBeTrue();
});

/**
 * Challenges cascade when a method is deleted, so an administrative reset takes
 * with it every trace of the codes already in the user's inbox — and those are
 * exactly the ones they type next. Recognised from a short-lived record kept
 * outside the rows.
 */
it('recognises a code whose challenge was deleted with its method', function (): void {
    $manager = app(Gabrielesbaiz\NovaTwoFactor\Otp\OtpCodeManager::class);

    $this->driver->beginEnrollment($this->user);
    $stale = sentCode();

    // The reset: methods go, and their challenges cascade.
    app(Gabrielesbaiz\NovaTwoFactor\Actions\ResetTwoFactor::class)($this->user, 'test');

    expect(TwoFactorChallenge::query()->count())->toBe(0);

    $this->driver->beginEnrollment($this->user);
    $method = $this->user->twoFactorMethods()->latest('id')->first();

    expect($manager->verify($method, $stale)->failure)->toBe(VerificationResult::SUPERSEDED)
        ->and($manager->verify($method, '000000')->failure)->toBe(VerificationResult::INVALID_CODE);
});

/**
 * A stopped worker must not become a login screen waiting forever for a code
 * nobody is sending. `ShouldQueue` is unconditional on the class, so the
 * connection is what honours the setting.
 */
it('sends the code on the request unless queueing is asked for', function (): void {
    config()->set('nova-two-factor.methods.email.queue', false);

    expect((new TwoFactorCodeNotification('123456', ChallengePurpose::Login, 300))->connection)->toBe('sync');

    config()->set('nova-two-factor.methods.email.queue', true);

    expect((new TwoFactorCodeNotification('123456', ChallengePurpose::Login, 300))->connection)->toBeNull();
});

/** The mail quotes `methods.email.ttl`, in the units that value actually is. */
it('states the expiry the configuration actually sets', function (): void {
    $render = fn (int $ttl): string => (string) (new TwoFactorCodeNotification('123456', ChallengePurpose::Login, $ttl))
        ->toMail($this->user)
        ->render();

    expect($render(300))->toContain('5 minutes')
        ->and($render(60))->toContain('a minute')->not->toContain('1 minutes')
        ->and($render(45))->toContain('45 seconds');
});
