<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Gabrielesbaiz\NovaTwoFactor\Enums\AuditEvent;
use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorAudit;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Gabrielesbaiz\NovaTwoFactor\TwoFactorManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Date;
use Workbench\App\Models\User;

/**
 * A believable organisation, for looking at rather than asserting on.
 *
 * The admin pages are mostly shape — a coverage ring, a method mix, a queue of
 * people to chase, an audit trail. All of that reads as broken on the single
 * seeded user the suite needs, so the screenshots in SCREENSHOTS.md and any
 * honest look at the dashboards come from here instead.
 *
 *     php vendor/bin/testbench db:seed --class="Workbench\\Database\\Seeders\\DemoSeeder"
 *
 * Deliberately not part of {@see DatabaseSeeder}: the tests depend on knowing
 * exactly who exists, and a demo that grows over time would rewrite their
 * expectations from underneath them.
 */
class DemoSeeder extends Seeder
{
    /**
     * Invented people. Any resemblance to a real colleague is the fault of the
     * alphabet.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const PEOPLE = [
        ['Alex Morgan', 'alex.morgan@example.com'],
        ['Priya Raman', 'priya.raman@example.com'],
        ['Tomas Novak', 'tomas.novak@example.com'],
        ['Lena Fischer', 'lena.fischer@example.com'],
        ['Marco Rossi', 'marco.rossi@example.com'],
        ['Hannah Weber', 'hannah.weber@example.com'],
        ['Diego Silva', 'diego.silva@example.com'],
        ['Yuki Tanaka', 'yuki.tanaka@example.com'],
        ['Sofia Ferrari', 'sofia.ferrari@example.com'],
        ['Omar Haddad', 'omar.haddad@example.com'],
        ['Claire Dubois', 'claire.dubois@example.com'],
        ['Ivan Petrov', 'ivan.petrov@example.com'],
    ];

    /**
     * Enrolment, by position in the list above. Five of twelve covered, one of
     * them on email alone, two phishing-resistant — a spread that makes every
     * figure on the overview non-zero and none of them flattering.
     *
     * @var array<int, array<int, string>>
     */
    private const ENROLMENT = [
        0 => [MethodType::WebAuthn->value, MethodType::Totp->value, MethodType::Email->value],
        1 => [MethodType::WebAuthn->value],
        2 => [MethodType::Totp->value],
        3 => [MethodType::Totp->value, MethodType::Email->value],
        4 => [MethodType::Email->value],
    ];

    public function run(): void
    {
        $users = $this->people();

        $this->enrol($users);

        // The first account signs in with a recovery code during a capture, so
        // it needs some — and the tile that counts people with none left needs
        // the others to have none.
        app(TwoFactorManager::class)->recoveryCodes()->regenerate($users->first());

        $this->history($users);

        $this->command?->info(sprintf(
            '%d users, %d methods, %d audit events.',
            $users->count(),
            TwoFactorMethod::query()->count(),
            TwoFactorAudit::query()->count(),
        ));
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function people()
    {
        foreach (self::PEOPLE as $index => [$name, $email]) {
            // Spread over the last year, because "time to first factor" and the
            // grace window are both measured from the account's own birthday.
            $joined = Date::now()->subDays(340 - $index * 26);

            User::query()->updateOrCreate(['email' => $email], [
                'name' => $name,
                'password' => 'password',
                'email_verified_at' => $joined,
                'created_at' => $joined,
            ]);
        }

        return User::query()->orderBy('id')->get();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, User>  $users
     */
    private function enrol($users): void
    {
        foreach ($users as $index => $user) {
            foreach (self::ENROLMENT[$index] ?? [] as $position => $type) {
                TwoFactorMethod::query()->updateOrCreate([
                    'authenticatable_type' => $user->getMorphClass(),
                    'authenticatable_id' => $user->getKey(),
                    'type' => $type,
                ], [
                    'name' => match ($type) {
                        MethodType::WebAuthn->value => 'MacBook Pro',
                        MethodType::Totp->value => 'Authenticator app',
                        default => 'Email code',
                    },
                    // The strongest factor a person has is the one they are
                    // asked for first.
                    'is_default' => $position === 0,
                    'secret' => $type === MethodType::Totp->value ? 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP' : null,
                    'destination' => $type === MethodType::Email->value ? $user->email : null,
                    'destination_hint' => $type === MethodType::Email->value
                        ? mb_substr($user->email, 0, 1).'****@example.com'
                        : null,
                    // A demo credential, never a usable one: the ceremony is
                    // what proves a passkey, and no stored blob can stand in
                    // for it.
                    'credential_id' => $type === MethodType::WebAuthn->value ? base64_encode('demo-'.$user->getKey()) : null,
                    'credential_id_hash' => $type === MethodType::WebAuthn->value
                        ? hash('sha256', base64_encode('demo-'.$user->getKey()))
                        : null,
                    'credential' => $type === MethodType::WebAuthn->value
                        ? json_encode(['publicKey' => 'demo', 'transports' => ['internal']])
                        : null,
                    'confirmed_at' => Date::now()->subDays(30 - $index * 3),
                    // One account has not verified anything in months, which is
                    // what the "stale" tile is counting.
                    'last_used_at' => $index === 4 ? Date::now()->subDays(120) : Date::now()->subHours($index * 5 + 2),
                ]);
            }
        }
    }

    /**
     * Enough of a trail that the activity page has something in every filter,
     * and the overview's lists are not empty.
     *
     * @param  \Illuminate\Support\Collection<int, User>  $users
     */
    private function history($users): void
    {
        $admin = $users[1];

        /** @var array<int, array{0: AuditEvent, 1: int, 2: int, 3: ?string, 4: ?array<string, mixed>}> */
        $events = [
            // Ordinary traffic.
            [AuditEvent::ChallengeSucceeded, 0, 3, MethodType::WebAuthn->value, null],
            [AuditEvent::ChallengeSucceeded, 2, 9, MethodType::Totp->value, null],
            [AuditEvent::ChallengeFailed, 4, 14, MethodType::Email->value, null],
            [AuditEvent::ChallengeFailed, 4, 15, MethodType::Email->value, null],
            [AuditEvent::ChallengeLockedOut, 4, 16, MethodType::Email->value, null],
            [AuditEvent::OtpSent, 4, 44, MethodType::Email->value, null],
            [AuditEvent::OtpSendThrottled, 4, 45, MethodType::Email->value, null],
            [AuditEvent::MethodEnrolled, 1, 26, MethodType::WebAuthn->value, null],
            [AuditEvent::MethodConfirmed, 1, 26, MethodType::WebAuthn->value, null],
            [AuditEvent::MethodDefaultChanged, 3, 30, MethodType::Totp->value, null],
            [AuditEvent::TrustedDeviceRegistered, 2, 40, MethodType::Totp->value, null],
            [AuditEvent::TrustedDeviceRevoked, 2, 41, MethodType::Totp->value, null],
            [AuditEvent::RecoveryCodeConsumed, 0, 34, null, null],
            [AuditEvent::RecoveryCodesRegenerated, 0, 35, null, null],
            [AuditEvent::EnforcementBlocked, 7, 64, null, null],

            // The suspicious filter, which is the one worth a second look.
            [AuditEvent::ReplayDetected, 2, 70, MethodType::Totp->value, null],
            [AuditEvent::StepUpDenied, 3, 52, MethodType::Totp->value, null],
            [AuditEvent::StepUpGranted, 0, 50, MethodType::WebAuthn->value, null],

            // Administrator actions, which the activity page opens on.
            [AuditEvent::SettingChanged, 0, 4, null, ['key' => 'enforcement.mode', 'from' => 'optional', 'to' => 'encouraged']],
            [AuditEvent::SettingChanged, 0, 26, null, ['key' => 'enforcement.grace_days', 'from' => 14, 'to' => 7]],
            [AuditEvent::SettingChanged, 0, 52, null, ['key' => 'methods.email.enabled', 'from' => true, 'to' => false]],
            [AuditEvent::SettingChanged, 0, 96, null, ['key' => 'trusted_devices.days', 'from' => 60, 'to' => 30]],
            [AuditEvent::AdminReset, 6, 8, null, ['reason' => 'Lost phone, identity confirmed by video call']],
            [AuditEvent::AdminReset, 9, 70, null, ['reason' => 'Left the company, access revoked']],
            [AuditEvent::AdminReminderSent, 7, 12, null, null],
            [AuditEvent::AdminReminderSent, 8, 12, null, null],
            [AuditEvent::AdminReminderSent, 10, 12, null, null],
            [AuditEvent::EnforcementPaused, 0, 30, null, ['minutes' => 60, 'reason' => 'Mail provider outage']],
            [AuditEvent::EnforcementResumed, 0, 29, null, null],
            [AuditEvent::AdminExempted, 11, 44, null, ['reason' => 'Service account, keys rotated quarterly']],
        ];

        TwoFactorAudit::query()->delete();

        foreach ($events as $index => [$event, $ownerIndex, $hoursAgo, $methodType, $context]) {
            $owner = $users[$ownerIndex] ?? $users->first();

            TwoFactorAudit::query()->create([
                'authenticatable_type' => $owner->getMorphClass(),
                'authenticatable_id' => $owner->getKey(),
                'event' => $event->value,
                'method_type' => $methodType,
                'ip' => '203.0.113.'.(10 + $index),
                // Administrator actions name who did them: a reset with no
                // actor is the one row this log must never show.
                'context' => in_array($event, AuditEvent::adminActions(), true)
                    ? array_merge($context ?? [], ['by' => $admin->getKey()])
                    : $context,
                // The audit table keeps `created_at` only: an entry that can be
                // updated is not an audit entry.
                'created_at' => Date::now()->subHours($hoursAgo),
            ]);
        }
    }
}
