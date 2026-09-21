<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Gabrielesbaiz\NovaTwoFactor\Support\Enforcement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Workbench\App\Models\Admin;
use Workbench\App\Models\User;

it('creates every two-factor table', function (): void {
    foreach (config('nova-two-factor.database.tables') as $table) {
        expect(Schema::hasTable($table))->toBeTrue("missing table [{$table}]");
    }
})->skip(fn (): bool => ! class_exists(Schema::class), 'Schema facade unavailable');

it('never stores a TOTP secret in plaintext', function (): void {
    $user = User::factory()->create();
    $secret = 'JBSWY3DPEHPK3PXP';

    $method = new TwoFactorMethod(['type' => MethodType::Totp, 'name' => 'App', 'secret' => $secret]);
    $method->authenticatable()->associate($user)->save();

    $stored = DB::table(config('nova-two-factor.database.tables.methods'))
        ->where('id', $method->getKey())
        ->value('secret');

    // The column must not contain the secret in any recoverable form, and must
    // still decrypt back to it through the cast. 1.x left this to an opt-in
    // config flag that defaulted to off.
    expect($stored)->not->toContain($secret)
        ->and($stored)->not->toBe($secret)
        ->and($method->fresh()->secret)->toBe($secret);
});

it('keeps the secret out of serialized output', function (): void {
    $user = User::factory()->create();

    $method = new TwoFactorMethod(['type' => MethodType::Totp, 'name' => 'App', 'secret' => 'JBSWY3DPEHPK3PXP']);
    $method->authenticatable()->associate($user)->save();

    $payload = $method->fresh()->toArray();

    foreach (['secret', 'credential', 'credential_id', 'credential_id_hash', 'destination'] as $key) {
        expect($payload)->not->toHaveKey($key);
    }
});

it('stores the morph alias rather than the class name', function (): void {
    $user = User::factory()->create();

    $method = new TwoFactorMethod(['type' => MethodType::Totp, 'name' => 'App']);
    $method->authenticatable()->associate($user)->save();

    expect(DB::table(config('nova-two-factor.database.tables.methods'))
        ->where('id', $method->getKey())
        ->value('authenticatable_type'))->toBe('user');
});

it('supports more than one authenticatable model', function (): void {
    $user = User::factory()->create();
    $admin = Admin::factory()->create();

    foreach ([$user, $admin] as $owner) {
        $method = new TwoFactorMethod(['type' => MethodType::Totp, 'name' => 'App', 'confirmed_at' => now()]);
        $method->authenticatable()->associate($owner)->save();
    }

    expect($user->hasTwoFactorEnabled())->toBeTrue()
        ->and($admin->hasTwoFactorEnabled())->toBeTrue()
        // Same primary key, different morph alias: these must not bleed.
        ->and($user->twoFactorMethods()->count())->toBe(1)
        ->and($admin->twoFactorMethods()->count())->toBe(1);
});

it('does not treat an unconfirmed enrollment as enabled', function (): void {
    $user = User::factory()->create();

    $method = new TwoFactorMethod(['type' => MethodType::Totp, 'name' => 'App']);
    $method->authenticatable()->associate($user)->save();

    // A half-finished setup must never gate a login. 1.x consulted a separate
    // `google2fa_enable` flag and ignored `confirmed` entirely.
    expect($user->hasTwoFactorEnabled())->toBeFalse();

    $method->update(['confirmed_at' => now()]);

    expect($user->refresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('builds the enforcement except-list from the configured Nova path', function (): void {
    config()->set('nova.path', '/backoffice/admin');

    $patterns = app(Enforcement::class)->exceptPatterns();

    // 1.x hardcoded `admin/login` and friends, so any app on a different Nova
    // path hit an infinite redirect loop the moment enforcement was enabled.
    expect($patterns)->toContain('backoffice/admin/login')
        ->and($patterns)->toContain('backoffice/admin/two-factor/*')
        ->and($patterns)->not->toContain('admin/login');
});

it('supports wildcards in the enforcement except-list', function (): void {
    $patterns = app(Enforcement::class)->exceptPatterns();

    expect(collect($patterns)->contains(fn (string $p): bool => str_contains($p, '*')))->toBeTrue();
});

it('refuses to write an audit context that looks like it carries a secret', function (): void {
    $audit = new Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorAudit([
        'event' => Gabrielesbaiz\NovaTwoFactor\Enums\AuditEvent::ChallengeSucceeded,
        'context' => ['totp_secret' => 'JBSWY3DPEHPK3PXP'],
    ]);

    expect(fn () => $audit->save())->toThrow(LogicException::class);
});

it('allows an audit context with no secret-shaped keys', function (): void {
    $user = User::factory()->create();

    $audit = new Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorAudit([
        'event' => Gabrielesbaiz\NovaTwoFactor\Enums\AuditEvent::RecoveryCodeConsumed,
        'method_type' => MethodType::Totp->value,
        'ip' => '203.0.113.7',
        'context' => ['remaining' => 7],
    ]);
    $audit->authenticatable()->associate($user);

    // Note the event name itself contains "code" — only the context payload is
    // inspected, or every legitimate recovery event would throw.
    expect(fn () => $audit->save())->not->toThrow(LogicException::class);
});

/**
 * The factor names reach the enforcement screen, the challenge chooser, the
 * security card, the audit log and three notifications. Returned as literals
 * from the enum, they were the one part of a fully translated page that stayed
 * in English.
 */
it('translates the factor names', function (): void {
    app()->setLocale('it');

    expect(MethodType::Totp->label())->toBe('App di autenticazione')
        ->and(MethodType::Email->label())->toBe('Codice via email');

    app()->setLocale('en');

    expect(MethodType::Totp->label())->toBe('Authenticator app');
});

/**
 * The published config is republished with `--force` on every package update in
 * at least one real deployment, so anything an environment needs to tune has to
 * survive that — which means reading from the environment, not from an edit to
 * the file.
 */
it('reads every tunable knob from the environment', function (): void {
    $config = file_get_contents(__DIR__.'/../../config/nova-two-factor.php');

    $tunable = [
        "'grace_days'", "'gate'", "'admin_gate'", "'show_method_tradeoffs'",
        "'password_confirmation_ttl'", "'resend_after'",
        "'per_user' => env('NOVA_TWO_FACTOR_LIMIT_ENROLL'",
    ];

    foreach ($tunable as $needle) {
        $line = collect(explode("\n", $config))->first(fn (string $l): bool => str_contains($l, $needle));

        expect($line)->toBeString()->toContain('env(');
    }
});
