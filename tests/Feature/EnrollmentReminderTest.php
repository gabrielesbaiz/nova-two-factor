<?php

declare(strict_types=1);

use Gabrielesbaiz\NovaTwoFactor\Enums\AuditEvent;
use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Events\EnrollmentReminderSent;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorAudit;
use Gabrielesbaiz\NovaTwoFactor\Notifications\EnrollmentReminderNotification;
use Gabrielesbaiz\NovaTwoFactor\Notifications\TwoFactorResetNotification;
use Gabrielesbaiz\NovaTwoFactor\Nova\Actions\ResetTwoFactorAuthentication;
use Gabrielesbaiz\NovaTwoFactor\Nova\Actions\SendEnrollmentReminder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Laravel\Nova\Fields\ActionFields;
use Workbench\App\Models\User;

function remindUrl(): string
{
    return '/'.trim(trim((string) config('nova.path'), '/').'/two-factor/compliance/remind', '/');
}

function reminderFields(array $values = []): ActionFields
{
    return new ActionFields(collect($values), collect());
}

it('mails users who have not enrolled', function (): void {
    Notification::fake();

    $users = User::factory()->count(2)->create();

    (new SendEnrollmentReminder)->handle(reminderFields(['note' => null]), collect($users));

    Notification::assertSentTo($users, EnrollmentReminderNotification::class);
});

it('skips a user who already has a confirmed factor', function (): void {
    // A reminder's failure mode is being ignored, and mailing people who have
    // already complied is the fastest way to get there.
    Notification::fake();

    $enrolled = User::factory()->create();
    $enrolled->twoFactorMethods()->create([
        'type' => MethodType::Totp,
        'name' => 'Authenticator',
        'secret' => 'x',
        'confirmed_at' => now(),
    ]);

    $pending = User::factory()->create();

    (new SendEnrollmentReminder)->handle(reminderFields(), collect([$enrolled, $pending]));

    Notification::assertSentTo($pending, EnrollmentReminderNotification::class);
    Notification::assertNotSentTo($enrolled, EnrollmentReminderNotification::class);
});

it('reports how many were sent and how many were skipped', function (): void {
    Notification::fake();

    $enrolled = User::factory()->create();
    $enrolled->twoFactorMethods()->create([
        'type' => MethodType::Totp,
        'name' => 'Authenticator',
        'secret' => 'x',
        'confirmed_at' => now(),
    ]);

    $response = (new SendEnrollmentReminder)->handle(
        reminderFields(),
        collect([$enrolled, User::factory()->create()]),
    );

    // Both numbers: "sent 1" over a selection of two reads as a failure unless
    // the page also says the other one was already covered.
    $message = (string) ($response->jsonSerialize()['message']?->text ?? '');

    expect($message)->toContain('Reminder sent to 1 user')
        ->toContain('1 user was skipped');
});

it('writes an audit row naming the administrator', function (): void {
    Notification::fake();

    $admin = User::factory()->create();
    $this->actingAs($admin);

    $target = User::factory()->create();

    (new SendEnrollmentReminder)->handle(reminderFields(['note' => 'Before Friday.']), collect([$target]));

    $audit = TwoFactorAudit::query()->where('event', AuditEvent::AdminReminderSent->value)->first();

    // An unexpected "set up two-factor" mail should be traceable to the admin
    // who sent it, not indistinguishable from an attacker's.
    expect($audit)->not->toBeNull()
        ->and($audit->context['by'] ?? null)->toBe($admin->getKey())
        ->and($audit->context['note'] ?? null)->toBe('Before Friday.');
});

it('dispatches the reminder event', function (): void {
    Notification::fake();
    Event::fake([EnrollmentReminderSent::class]);

    (new SendEnrollmentReminder)->handle(reminderFields(), collect([User::factory()->create()]));

    Event::assertDispatched(EnrollmentReminderSent::class);
});

it('is hidden from anyone the admin gate refuses', function (): void {
    config()->set('nova-two-factor.nova.admin_gate', 'manage-two-factor');

    Gate::define('manage-two-factor', fn (User $user): bool => $user->email === 'admin@example.com');

    $action = new SendEnrollmentReminder;

    $allowed = User::factory()->create(['email' => 'admin@example.com']);
    $denied = User::factory()->create(['email' => 'staff@example.com']);

    expect($action->authorizedToSee(request()->setUserResolver(fn () => $allowed)))->toBeTrue()
        ->and($action->authorizedToSee(request()->setUserResolver(fn () => $denied)))->toBeFalse();
});

it('carries the grace deadline and the note into the mail', function (): void {
    config()->set('nova-two-factor.enforcement.mode', 'required');
    config()->set('nova-two-factor.enforcement.grace_days', 30);

    Notification::fake();

    $user = User::factory()->create(['created_at' => now()->subDays(10)]);

    (new SendEnrollmentReminder)->handle(reminderFields(['note' => 'Security review.']), collect([$user]));

    Notification::assertSentTo($user, EnrollmentReminderNotification::class, function ($notification) use ($user): bool {
        $mail = $notification->toMail($user);
        $rendered = implode(' ', array_map('strval', $mail->introLines));

        return str_contains($rendered, 'Security review.')
            && str_contains($rendered, now()->addDays(20)->isoFormat('LL'));
    });
});

it('sends an invitation under encouraged, with no deadline', function (): void {
    // A date printed as a deadline under a mode that never blocks is a threat
    // the application cannot carry out — and a deadline nobody enforces
    // teaches people the next one is not real either.
    config()->set('nova-two-factor.enforcement.mode', 'encouraged');
    config()->set('nova-two-factor.enforcement.grace_days', 7);

    Notification::fake();

    $user = User::factory()->create(['created_at' => now()]);

    (new SendEnrollmentReminder)->handle(reminderFields(), collect([$user]));

    Notification::assertSentTo($user, EnrollmentReminderNotification::class, function ($notification) use ($user): bool {
        $mail = $notification->toMail($user);
        $body = implode(' ', array_map('strval', $mail->introLines));

        return str_contains((string) $mail->subject, 'Protect')
            && ! str_contains($body, 'Please set it up by')
            && str_contains($body, 'not required');
    });
});

it('sends a warning with the date under required', function (): void {
    config()->set('nova-two-factor.enforcement.mode', 'required');
    config()->set('nova-two-factor.enforcement.grace_days', 7);

    Notification::fake();

    $user = User::factory()->create(['created_at' => now()]);

    (new SendEnrollmentReminder)->handle(reminderFields(), collect([$user]));

    Notification::assertSentTo($user, EnrollmentReminderNotification::class, function ($notification) use ($user): bool {
        $mail = $notification->toMail($user);
        $body = implode(' ', array_map('strval', $mail->introLines));

        return str_contains((string) $mail->subject, 'Action needed')
            && str_contains($body, 'Please set it up by')
            && str_contains($body, now()->addDays(7)->isoFormat('LL'));
    });
});

it('tells the user about a reset only when asked', function (): void {
    // Unticked by default: a reset is usually part of a support call the user
    // is already on, and a mail arriving mid-conversation is noise.
    Notification::fake();

    $target = User::factory()->create();

    (new ResetTwoFactorAuthentication)->handle(
        reminderFields(['reason' => 'Lost their phone.']),
        collect([$target]),
    );

    Notification::assertNotSentTo($target, TwoFactorResetNotification::class);

    (new ResetTwoFactorAuthentication)->handle(
        reminderFields(['reason' => 'Lost their phone.', 'notify' => true]),
        collect([$target]),
    );

    Notification::assertSentTo($target, TwoFactorResetNotification::class);
});

it('never puts the administrator’s reason in the user’s mail', function (): void {
    // The reason is written for the audit log and may name an incident or
    // other people; what the user needs is the fact and the next step.
    $user = User::factory()->create();

    $mail = (new TwoFactorResetNotification(null, true))->toMail($user);
    $body = implode(' ', array_map('strval', array_merge($mail->introLines, $mail->outroLines)));

    expect($body)->not->toContain('Lost their phone')
        ->and($body)->toContain('What to do now')
        // The line that makes the mail worth sending at all.
        ->and($body)->toContain('If you did not ask for this');
});

it('renders in the locale it was sent from, not the queue worker’s', function (): void {
    // A queued notification is rendered later by a worker with no request
    // behind it, so it falls back to `app.locale` — English on an application
    // that picks the language per request. The mail then arrives in the wrong
    // language for everybody, which is what happened here.
    app()->setLocale('it');

    $notification = new TwoFactorResetNotification(null, false);

    expect($notification->locale)->toBe('it');

    app()->setLocale('en');

    // Still Italian: the locale travelled with the notification.
    expect($notification->locale)->toBe('it');
});

it('refuses a second reminder to the same person inside the cooldown', function (): void {
    config()->set('nova-two-factor.enforcement.remind_cooldown_hours', 24);

    $admin = User::factory()->create();
    $target = User::factory()->create();

    $send = fn () => $this->actingAs($admin)->postJson(remindUrl(), [
        'model' => User::class,
        'id' => $target->getKey(),
    ]);

    $send()->assertOk();

    // Whoever asks, and however they ask: the cooldown is keyed on the inbox
    // this lands in, not on the session that requested it.
    $send()->assertStatus(429);

    expect(TwoFactorAudit::query()->where('event', AuditEvent::AdminReminderSent->value)->count())->toBe(1);
});

it('lets the reminder through again once the cooldown has passed', function (): void {
    config()->set('nova-two-factor.enforcement.remind_cooldown_hours', 24);

    $admin = User::factory()->create();
    $target = User::factory()->create();

    $send = fn () => $this->actingAs($admin)->postJson(remindUrl(), [
        'model' => User::class,
        'id' => $target->getKey(),
    ]);

    $send()->assertOk();

    $this->travel(25)->hours();

    $send()->assertOk();
});

it('sends without a cooldown when it is turned off', function (): void {
    // Zero is the pre-existing behaviour, and a legitimate choice for a team
    // small enough to coordinate by talking to each other.
    config()->set('nova-two-factor.enforcement.remind_cooldown_hours', 0);

    $admin = User::factory()->create();
    $target = User::factory()->create();

    $send = fn () => $this->actingAs($admin)->postJson(remindUrl(), [
        'model' => User::class,
        'id' => $target->getKey(),
    ]);

    $send()->assertOk();
    $send()->assertOk();
});

it('skips a recently reminded user in the bulk action rather than failing the batch', function (): void {
    config()->set('nova-two-factor.enforcement.remind_cooldown_hours', 24);

    Notification::fake();

    $chased = User::factory()->create();
    $fresh = User::factory()->create();

    TwoFactorAudit::query()->create([
        'authenticatable_type' => $chased->getMorphClass(),
        'authenticatable_id' => $chased->getKey(),
        'event' => AuditEvent::AdminReminderSent->value,
        'created_at' => now()->subHour(),
    ]);

    (new SendEnrollmentReminder)->handle(reminderFields(), collect([$chased, $fresh]));

    Notification::assertSentTo($fresh, EnrollmentReminderNotification::class);
    Notification::assertNotSentTo($chased, EnrollmentReminderNotification::class);
});
