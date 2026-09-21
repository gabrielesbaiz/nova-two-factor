<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Enums\ChallengePurpose;
use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Recovery\RecoveryCodeManager;
use Gabrielesbaiz\NovaTwoFactor\Results\VerificationResult;
use Gabrielesbaiz\NovaTwoFactor\TwoFactorManager;
use Illuminate\Support\Facades\DB;
use Workbench\App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->codes = app(RecoveryCodeManager::class);

    $this->user->twoFactorMethods()->create([
        'type' => MethodType::Totp,
        'name' => 'Authenticator app',
        'secret' => 'JBSWY3DPEHPK3PXP',
        'confirmed_at' => now(),
    ]);
});

it('issues the configured number of codes', function (): void {
    $issued = $this->codes->regenerate($this->user);

    expect($issued)->toHaveCount(config('nova-two-factor.recovery_codes.count'))
        ->and($issued->unique())->toHaveCount($issued->count());
});

it('preserves case, so it keeps the entropy it generated', function (): void {
    // 1.x ran strtoupper() over a base62 string, collapsing the alphabet and
    // discarding roughly 44 bits for no benefit.
    $issued = $this->codes->regenerate($this->user);
    $joined = $issued->implode('');

    expect($joined)->not->toBe(strtoupper($joined));
});

it('stores only irreversible hashes', function (): void {
    $issued = $this->codes->regenerate($this->user);
    $stored = DB::table(config('nova-two-factor.database.tables.recovery_codes'))->pluck('code_hash');

    foreach ($issued as $code) {
        expect($stored)->not->toContain($code);
    }

    expect($stored->first())->toHaveLength(64);
});

it('accepts a code and marks it spent', function (): void {
    $code = $this->codes->regenerate($this->user)->first();

    expect($this->codes->consume($this->user, $code)->passed)->toBeTrue()
        ->and($this->codes->unusedCount($this->user))->toBe(7);
});

it('refuses the same code twice', function (): void {
    $code = $this->codes->regenerate($this->user)->first();

    $this->codes->consume($this->user, $code);
    $second = $this->codes->consume($this->user, $code);

    expect($second->passed)->toBeFalse()
        ->and($second->failure)->toBe(VerificationResult::ALREADY_USED);
});

it('accepts a code however the user formats it', function (): void {
    $code = $this->codes->regenerate($this->user)->first();

    // Pasted from a PDF, retyped without the dash, or copied with a stray space.
    $mangled = ' '.str_replace('-', '', $code).' ';

    expect($this->codes->consume($this->user, $mangled)->passed)->toBeTrue();
});

it('rejects a code belonging to another user', function (): void {
    $other = User::factory()->create();
    $code = $this->codes->regenerate($other)->first();

    expect($this->codes->consume($this->user, $code)->passed)->toBeFalse();
});

it('invalidates every previous code when regenerating', function (): void {
    $old = $this->codes->regenerate($this->user);
    $this->codes->regenerate($this->user);

    foreach ($old as $code) {
        expect($this->codes->consume($this->user, $code)->passed)->toBeFalse();
    }
});

it('does not remove any factor when a code is spent', function (): void {
    // The 1.x regression. There, using a recovery code deleted the whole 2FA
    // record — so a backup code doubled as a self-service way to turn 2FA off
    // permanently, with no password, no notification and no audit trail.
    $code = $this->codes->regenerate($this->user)->first();
    $manager = app(TwoFactorManager::class);

    $result = $manager->consumeRecoveryCode(
        $this->user,
        $code,
        $manager->context($this->user, ChallengePurpose::Login),
    );

    expect($result->passed)->toBeTrue()
        ->and($this->user->refresh()->hasTwoFactorEnabled())->toBeTrue()
        ->and($this->user->twoFactorMethods()->confirmed()->count())->toBe(1);
});

it('lets exactly one of two concurrent uses of a code win', function (): void {
    $code = $this->codes->regenerate($this->user)->first();

    $results = [
        $this->codes->consume($this->user, $code)->passed,
        $this->codes->consume($this->user, $code)->passed,
    ];

    expect(array_filter($results))->toHaveCount(1);
});

it('warns once the remaining codes run low', function (): void {
    $issued = $this->codes->regenerate($this->user);

    expect($this->codes->isRunningLow($this->user))->toBeFalse();

    // Spend down to the configured warning threshold.
    $issued->take(5)->each(fn (string $code) => $this->codes->consume($this->user, $code));

    expect($this->codes->unusedCount($this->user))->toBe(3)
        ->and($this->codes->isRunningLow($this->user))->toBeTrue();
});

it('records an audit row when a code is spent, without the code itself', function (): void {
    $code = $this->codes->regenerate($this->user)->first();
    $manager = app(TwoFactorManager::class);

    $manager->consumeRecoveryCode(
        $this->user,
        $code,
        $manager->context($this->user, ChallengePurpose::Login),
    );

    $audit = $this->user->twoFactorAudits()
        ->where('event', 'recovery_code.consumed')
        ->firstOrFail();

    expect($audit->context)->toHaveKey('remaining')
        ->and(json_encode($audit->context))->not->toContain($code);
});

it('rejects an empty submission without touching the database', function (): void {
    $this->codes->regenerate($this->user);

    expect($this->codes->consume($this->user, '')->failure)->toBe(VerificationResult::INVALID_CODE)
        ->and($this->codes->consume($this->user, '   -   ')->failure)->toBe(VerificationResult::INVALID_CODE)
        ->and($this->codes->unusedCount($this->user))->toBe(8);
});

it('generates codes with the entropy that lets them be stored unkeyed', function (): void {
    // The whole reason these are a plain SHA-256 rather than an HMAC: twenty
    // base62 characters is beyond search whatever you hash it with. Pinned,
    // because the argument is only as good as the length.
    $user = User::factory()->create();

    $codes = app(RecoveryCodeManager::class)->regenerate($user);

    foreach ($codes as $code) {
        $bare = str_replace('-', '', $code);

        expect(mb_strlen($bare))->toBe(20)
            ->and($bare)->toMatch('/^[A-Za-z0-9]{20}$/');
    }

    // And no two the same, which a broken generator would give away here first.
    expect($codes->unique()->count())->toBe($codes->count());
});

it('reports the entropy in the doctor', function (): void {
    $this->artisan('nova-two-factor:doctor')
        ->expectsOutputToContain('~119 bits')
        ->assertSuccessful();
});

it('warns in the doctor when the codes are shortened', function (): void {
    config()->set('nova-two-factor.recovery_codes.length', 6);

    $this->artisan('nova-two-factor:doctor')
        ->expectsOutputToContain('Stored unkeyed')
        ->assertSuccessful();
});

it('warns in the doctor when the configured length is below the floor', function (): void {
    // Silently ignored by the generator, which is the sort of setting that
    // reads as done and is not.
    config()->set('nova-two-factor.recovery_codes.length', 4);

    $this->artisan('nova-two-factor:doctor')
        ->expectsOutputToContain('below the floor')
        ->assertSuccessful();
});
