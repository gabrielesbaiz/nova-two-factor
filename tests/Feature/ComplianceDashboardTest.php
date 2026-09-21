<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Enums\AuditEvent;
use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorAudit;
use Gabrielesbaiz\NovaTwoFactor\Nova\Cards\TwoFactorComplianceOverview;
use Gabrielesbaiz\NovaTwoFactor\Nova\Dashboards\TwoFactorCompliance;
use Gabrielesbaiz\NovaTwoFactor\NovaTwoFactor;
use Gabrielesbaiz\NovaTwoFactor\Support\AuditedModels;
use Gabrielesbaiz\NovaTwoFactor\Support\Enforcement;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Laravel\Nova\Menu\MenuItem;
use Laravel\Nova\Menu\MenuSection;
use Laravel\Nova\Nova;
use Workbench\App\Models\Admin;
use Workbench\App\Models\User;

function complianceUrl(): string
{
    return '/'.trim(trim((string) config('nova.path'), '/').'/two-factor/compliance', '/');
}

beforeEach(fn () => AuditedModels::flush());

afterEach(fn () => AuditedModels::flush());

it('registers the compliance dashboard with Nova', function (): void {
    $keys = collect(Nova::$dashboards)->map(fn ($dashboard): string => $dashboard->uriKey());

    expect($keys)->toContain('two-factor-compliance');
});

it('offers the entry automatically, and withholds it when the menu is off', function (): void {
    $tool = NovaTwoFactor::make();
    $request = request();

    expect($tool->menu($request))->toBeInstanceOf(MenuSection::class);

    config()->set('nova-two-factor.nova.menu.show', false);

    // Withheld, not removed: the dashboard is still registered, so a host that
    // turns the automatic entry off can place `menuSection()` wherever it likes.
    expect($tool->menu($request))->toBeNull()
        ->and(NovaTwoFactor::menuSection())->toBeInstanceOf(MenuSection::class)
        ->and(NovaTwoFactor::menuItem())->toBeInstanceOf(MenuItem::class);
});

it('points both helpers at the dashboard route', function (): void {
    expect(NovaTwoFactor::menuSection()->path)->toBe('/dashboards/two-factor-compliance')
        ->and(NovaTwoFactor::menuItem()->path)->toBe('/dashboards/two-factor-compliance');
});

it('carries the admin gate on a hand-placed entry', function (): void {
    // The whole point of handing out the item: placing it yourself must not be
    // a way to expose compliance figures to everyone.
    config()->set('nova-two-factor.nova.admin_gate', 'manage-two-factor');

    Gate::define('manage-two-factor', fn (User $user): bool => $user->email === 'admin@example.com');

    $section = NovaTwoFactor::menuSection();

    $allowed = User::factory()->create(['email' => 'admin@example.com']);
    $denied = User::factory()->create(['email' => 'staff@example.com']);

    expect($section->authorizedToSee(request()->setUserResolver(fn () => $allowed)))->toBeTrue()
        ->and($section->authorizedToSee(request()->setUserResolver(fn () => $denied)))->toBeFalse();
});

it('lets everyone through when no admin gate is configured', function (): void {
    config()->set('nova-two-factor.nova.admin_gate', null);

    $user = User::factory()->create();

    expect((new TwoFactorCompliance)->authorizedToSee(request()->setUserResolver(fn () => $user)))
        ->toBeTrue();
});

it('badges the entry with the number of overdue users', function (): void {
    config()->set('nova-two-factor.enforcement.mode', 'required');
    config()->set('nova-two-factor.enforcement.grace_days', 0);

    User::factory()->count(2)->create(['created_at' => now()->subYears(2)]);

    $overdue = app(Enforcement::class)->overdueCount();

    expect($overdue)->toBe(2);
});

it('does not count a user who has a confirmed factor', function (): void {
    config()->set('nova-two-factor.enforcement.mode', 'required');
    config()->set('nova-two-factor.enforcement.grace_days', 0);

    $user = User::factory()->create(['created_at' => now()->subYears(2)]);

    $user->twoFactorMethods()->create([
        'type' => MethodType::Totp,
        'name' => 'Authenticator',
        'secret' => 'x',
        'confirmed_at' => now(),
    ]);

    expect(app(Enforcement::class)->overdueCount())->toBe(0);
});

it('counts nobody while the mode does not block', function (): void {
    // `optional` and `encouraged` have no overdue population by definition, and
    // a red badge over a mode that never blocks is a false alarm.
    config()->set('nova-two-factor.enforcement.mode', 'encouraged');
    config()->set('nova-two-factor.enforcement.grace_days', 0);

    User::factory()->count(2)->create(['created_at' => now()->subYears(2)]);

    expect(app(Enforcement::class)->overdueCount())->toBe(0);
});

it('counts only the audited populations', function (): void {
    // The case this exists for: a panel only administrators can reach, where
    // counting every customer row makes adoption look comfortably high.
    config()->set('nova-two-factor.enforcement.mode', 'required');
    config()->set('nova-two-factor.enforcement.grace_days', 0);

    User::factory()->count(3)->create(['created_at' => now()->subYears(2)]);
    Admin::factory()->count(2)->create(['created_at' => now()->subYears(2)]);

    // Default: whatever model Nova's guard resolves to.
    expect(app(Enforcement::class)->overdueCount())->toBe(3);

    AuditedModels::register(Admin::class);

    expect(app(Enforcement::class)->overdueCount())->toBe(2);
});

it('narrows an audited population with a scope', function (): void {
    config()->set('nova-two-factor.enforcement.mode', 'required');
    config()->set('nova-two-factor.enforcement.grace_days', 0);

    User::factory()->count(2)->create(['created_at' => now()->subYears(2), 'email_verified_at' => null]);
    User::factory()->create(['created_at' => now()->subYears(2), 'email_verified_at' => now()]);

    NovaTwoFactor::make()->audit(User::class, fn ($query) => $query->whereNotNull('email_verified_at'));

    expect(app(Enforcement::class)->overdueCount())->toBe(1);
});

it('reads audited models from configuration', function (): void {
    config()->set('nova-two-factor.enforcement.mode', 'required');
    config()->set('nova-two-factor.enforcement.grace_days', 0);
    config()->set('nova-two-factor.nova.compliance.models', [Admin::class]);

    User::factory()->count(3)->create(['created_at' => now()->subYears(2)]);
    Admin::factory()->create(['created_at' => now()->subYears(2)]);

    expect(app(Enforcement::class)->overdueCount())->toBe(1);
});

it('serves the rollup and the queue behind the admin gate', function (): void {
    config()->set('nova-two-factor.enforcement.mode', 'required');
    config()->set('nova-two-factor.enforcement.grace_days', 0);

    $enrolled = User::factory()->create(['name' => 'Anna Conti']);
    $enrolled->twoFactorMethods()->create([
        'type' => MethodType::Email,
        'name' => 'Email code',
        'confirmed_at' => now(),
        'last_used_at' => now()->subDay(),
    ]);

    User::factory()->create(['name' => 'Luca Bianchi', 'created_at' => now()->subYears(2)]);

    $response = $this->actingAs($enrolled)->getJson(complianceUrl());

    $response->assertOk();

    expect($response->json('summary.in_scope'))->toBe(2)
        ->and($response->json('summary.enrolled'))->toBe(1)
        ->and($response->json('summary.enrolled_percent'))->toBe(50)
        ->and($response->json('summary.overdue'))->toBe(1);

    // Overdue first: the page is a queue of people to chase, and sorting it by
    // name would bury them.
    expect($response->json('rows.0.name'))->toBe('Luca Bianchi')
        ->and($response->json('rows.0.status'))->toBe('overdue')
        ->and($response->json('rows.0.last_verified_at'))->toBeNull()
        ->and($response->json('rows.1.status'))->toBe('enrolled')
        ->and($response->json('rows.1.methods.0.type'))->toBe('email');
});

it('refuses the compliance data to anyone the gate refuses', function (): void {
    // Hiding the menu entry is not access control: the URL is guessable and the
    // endpoint behind it is the thing that actually holds the figures.
    config()->set('nova-two-factor.nova.admin_gate', 'manage-two-factor');

    Gate::define('manage-two-factor', fn (User $user): bool => $user->email === 'admin@example.com');

    $this->actingAs(User::factory()->create(['email' => 'staff@example.com']))
        ->getJson(complianceUrl())
        ->assertForbidden();

    $this->actingAs(User::factory()->create(['email' => 'admin@example.com']))
        ->getJson(complianceUrl())
        ->assertOk();
});

it('narrows the queue with a search', function (): void {
    User::factory()->create(['name' => 'Marta Rossi']);
    User::factory()->create(['name' => 'Luca Bianchi']);

    $response = $this->actingAs(User::factory()->create())
        ->getJson(complianceUrl().'?search=Marta');

    expect($response->json('rows'))->toHaveCount(1)
        ->and($response->json('rows.0.name'))->toBe('Marta Rossi');
});

it('builds an endpoint that survives Nova being served from the root', function (): void {
    // `/${''}/${prefix}/compliance` is `//two-factor/compliance` — a
    // protocol-relative URL aimed at a host called `two-factor`, which is why
    // this is assembled in PHP and asserted here.
    config()->set('nova.path', '');

    expect((new TwoFactorComplianceOverview)->jsonSerialize()['endpoint'])
        ->toBe('/two-factor/compliance');

    config()->set('nova.path', '/admin');

    expect((new TwoFactorComplianceOverview)->jsonSerialize()['endpoint'])
        ->toBe('/admin/two-factor/compliance');
});

it('hides the figures a mode cannot honestly show', function (): void {
    // Not merely quiet in the wrong mode — wrong. Under `optional` nothing is
    // due, so nothing can be overdue, and a coverage strip where every row
    // reads "not required" is one grey band pretending to be information.
    config()->set('nova-two-factor.enforcement.mode', 'optional');

    $admin = User::factory()->create();
    $sections = $this->actingAs($admin)->getJson(complianceUrl())->json('sections');

    expect($sections['coverage'])->toBeFalse()
        ->and($sections['grace'])->toBeFalse()
        ->and($sections['trusted_devices'])->toBeFalse()
        ->and($sections['resilience'])->toBeTrue()
        ->and($sections['signals'])->toBeTrue();

    config()->set('nova-two-factor.enforcement.mode', 'required');
    $sections = $this->actingAs($admin)->getJson(complianceUrl())->json('sections');

    expect($sections['coverage'])->toBeTrue()
        ->and($sections['grace'])->toBeTrue();
});

it('carries one figure that only makes sense in the current mode', function (): void {
    $admin = User::factory()->create();

    config()->set('nova-two-factor.enforcement.mode', 'required');
    expect($this->actingAs($admin)->getJson(complianceUrl())->json('spotlight.key'))->toBe('blocked');

    config()->set('nova-two-factor.enforcement.mode', 'encouraged');
    expect($this->actingAs($admin)->getJson(complianceUrl())->json('spotlight.key'))->toBe('reminders');

    config()->set('nova-two-factor.enforcement.mode', 'optional');
    expect($this->actingAs($admin)->getJson(complianceUrl())->json('spotlight.key'))->toBe('new');
});

it('tags each row with the tiles it belongs to', function (): void {
    // The tiles filter the queue, so a row has to say which of them it counts
    // towards — otherwise the figure is a number nobody can act on.
    $lonely = User::factory()->create(['name' => 'Solo Metodo']);
    $lonely->twoFactorMethods()->create([
        'type' => MethodType::Totp,
        'name' => 'Authenticator',
        'secret' => 'x',
        'confirmed_at' => now(),
        'last_used_at' => now()->subYear(),
    ]);

    $rows = collect(
        $this->actingAs($lonely)->getJson(complianceUrl())->json('rows'),
    )->keyBy('name');

    expect($rows['Solo Metodo']['flags'])->toContain('single_factor')
        ->toContain('recovery')   // none generated, so zero left
        ->toContain('stale');     // last used a year ago
});

it('names the account behind an audited event under an enforced morph map', function (): void {
    // The suite enforces a morph map, exactly as an application that has ever
    // renamed a model must. That stores the alias — `user` — in
    // `authenticatable_type`, and resolving it with `class_exists()` alone
    // fails for every row, which turned every name on this page into
    // "Unknown": the recovery-code sign-ins, the lockouts and the
    // administrator actions all at once.
    $admin = User::factory()->create(['name' => 'Ada Lovelace']);

    TwoFactorAudit::query()->create([
        'authenticatable_type' => $admin->getMorphClass(),
        'authenticatable_id' => $admin->getKey(),
        'event' => AuditEvent::RecoveryCodeConsumed->value,
        'ip' => '203.0.113.1',
        'created_at' => now()->subHour(),
    ]);

    TwoFactorAudit::query()->create([
        'authenticatable_type' => $admin->getMorphClass(),
        'authenticatable_id' => $admin->getKey(),
        'event' => AuditEvent::AdminReset->value,
        'ip' => '203.0.113.2',
        'created_at' => now()->subHours(2),
    ]);

    $response = $this->actingAs($admin)->getJson(complianceUrl());

    expect($admin->getMorphClass())->toBe('user')
        ->and($response->json('recovery_sign_ins.0.name'))->toBe('Ada Lovelace')
        ->and($response->json('events.0.user'))->toBe('Ada Lovelace');
});

it('resolves the owners of a list in one query per model', function (): void {
    // A name per row is a query per row. These lists are short, but the page
    // reads several of them on every load, and the fix for the morph map is
    // also the place this can silently regress.
    $owners = User::factory()->count(5)->create();

    foreach ($owners as $owner) {
        TwoFactorAudit::query()->create([
            'authenticatable_type' => $owner->getMorphClass(),
            'authenticatable_id' => $owner->getKey(),
            'event' => AuditEvent::RecoveryCodeConsumed->value,
            'ip' => '203.0.113.9',
            'created_at' => now()->subMinutes((int) $owner->getKey()),
        ]);
    }

    $queries = 0;
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, '"users"') || str_contains($query->sql, '`users`')) {
            $queries++;
        }
    });

    $response = $this->actingAs($owners->first())->getJson(complianceUrl());

    expect($response->json('recovery_sign_ins'))->toHaveCount(5)
        ->and(collect($response->json('recovery_sign_ins'))->pluck('name'))->not->toContain('Unknown');

    // The page reads the user table for its own queue and for the lists it
    // primes — a handful of queries, never one per row.
    expect($queries)->toBeLessThan(10);
});

it('ships pointing at an ability, so the admin pages start closed', function (): void {
    // The default is a name, not null: `Gate::allows()` denies an ability that
    // does not exist, so a fresh install hides the compliance figures until
    // somebody says who may see them. The suite defines it — see TestCase —
    // which is what a configured application does.
    expect(config('nova-two-factor.nova.admin_gate'))->toBe('nova-two-factor:admin');
});

it('refuses the compliance data while the ability is undefined', function (): void {
    // What a fresh install actually experiences.
    config()->set('nova-two-factor.nova.admin_gate', 'nova-two-factor:admin-not-yet-defined');

    $this->actingAs(User::factory()->create())
        ->getJson(complianceUrl())
        ->assertForbidden();
});

it('opens to every Nova user only when that is said explicitly', function (): void {
    // Null is still supported, and right for a single-administrator panel —
    // but it has to be chosen rather than inherited.
    config()->set('nova-two-factor.nova.admin_gate', null);

    $this->actingAs(User::factory()->create())
        ->getJson(complianceUrl())
        ->assertOk();
});
