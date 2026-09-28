<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Actions\ResetTwoFactor;
use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Gabrielesbaiz\NovaTwoFactor\Support\Enforcement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Workbench\App\Models\Admin;

/**
 * @return array{0: Admin, 1: TwoFactorMethod}
 */
function enrolledAdmin(): array
{
    $admin = Admin::factory()->create(['created_at' => now()->subYears(1)]);

    $method = TwoFactorMethod::query()->create([
        'authenticatable_type' => $admin->getMorphClass(),
        'authenticatable_id' => $admin->getKey(),
        'type' => MethodType::Totp,
        'name' => 'Phone',
        'secret' => 'JBSWY3DPEHPK3PXP',
        'confirmed_at' => now(),
        'is_default' => true,
    ]);

    return [$admin, $method];
}

/**
 * @param  callable(): mixed  $work
 * @return array<int, string>
 */
function sqlDuring(callable $work): array
{
    $sqls = [];

    DB::listen(function ($query) use (&$sqls): void {
        $sqls[] = $query->sql;
    });

    $work();

    DB::flushQueryLog();

    return $sqls;
}

it('asks whether a user holds a confirmed factor once per request', function (): void {
    [$admin] = enrolledAdmin();

    config()->set('nova-two-factor.enforcement.mode', 'required');

    $sqls = sqlDuring(fn () => $this->actingAs($admin)->get('/nova/dashboards/main'));

    $asked = array_filter(
        $sqls,
        fn (string $sql): bool => str_contains($sql, 'two_factor_methods') && str_contains($sql, 'exists'),
    );

    // Both middlewares run, and the enrollment one consults the predicate twice
    // on its own. Without the memo this request issued three identical queries.
    expect($asked)->toHaveCount(1);
});

it('evaluates the enforcement gate once per request', function (): void {
    [$admin] = enrolledAdmin();

    config()->set('nova-two-factor.enforcement.mode', 'required');
    config()->set('nova-two-factor.enforcement.gate', 'nova-two-factor:enforce');

    $calls = 0;
    Gate::define('nova-two-factor:enforce', function () use (&$calls): bool {
        $calls++;

        return true;
    });

    $this->actingAs($admin)->get('/nova/dashboards/main');

    expect($calls)->toBe(1);
});

it('sees a factor confirmed after it has already answered', function (): void {
    $admin = Admin::factory()->create(['created_at' => now()->subYears(1)]);

    expect($admin->hasTwoFactorEnabled())->toBeFalse();

    TwoFactorMethod::query()->create([
        'authenticatable_type' => $admin->getMorphClass(),
        'authenticatable_id' => $admin->getKey(),
        'type' => MethodType::Totp,
        'name' => 'Phone',
        'secret' => 'JBSWY3DPEHPK3PXP',
        'confirmed_at' => now(),
    ]);

    expect($admin->hasTwoFactorEnabled())->toBeTrue();
});

it('sees a factor confirmed by an update rather than an insert', function (): void {
    $admin = Admin::factory()->create(['created_at' => now()->subYears(1)]);

    $method = TwoFactorMethod::query()->create([
        'authenticatable_type' => $admin->getMorphClass(),
        'authenticatable_id' => $admin->getKey(),
        'type' => MethodType::Totp,
        'name' => 'Phone',
        'secret' => 'JBSWY3DPEHPK3PXP',
    ]);

    expect($admin->hasTwoFactorEnabled())->toBeFalse();

    $method->update(['confirmed_at' => now()]);

    expect($admin->hasTwoFactorEnabled())->toBeTrue();
});

it('sees a reset that clears factors through the relation', function (): void {
    [$admin] = enrolledAdmin();

    expect($admin->hasTwoFactorEnabled())->toBeTrue();

    // A relation-level delete fires no model event, which is why the reset
    // also has to announce itself through the package's own event.
    app(ResetTwoFactor::class)($admin, 'test reset');

    expect($admin->hasTwoFactorEnabled())->toBeFalse();
});

it('does not confuse two users behind the same identifier', function (): void {
    [$admin] = enrolledAdmin();

    $other = Workbench\App\Models\User::factory()->create();

    expect($admin->hasTwoFactorEnabled())->toBeTrue()
        ->and($other->hasTwoFactorEnabled())->toBeFalse();
});

it('rebuilds the except-list when the Nova path changes', function (): void {
    $enforcement = app(Enforcement::class);

    config()->set('nova.path', '/backoffice');
    expect($enforcement->exceptPatterns())->toContain('backoffice/login');

    // The list is memoized per request, so a test changing config mid-request
    // has to say so — the same call the package makes when a setting is written.
    config()->set('nova.path', '/panel');
    $enforcement->flush();

    expect($enforcement->exceptPatterns())->toContain('panel/login')
        ->and($enforcement->exceptPatterns())->not->toContain('backoffice/login');
});
