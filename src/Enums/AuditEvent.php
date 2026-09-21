<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Enums;

enum AuditEvent: string
{
    case MethodEnrolled = 'method.enrolled';
    case MethodConfirmed = 'method.confirmed';
    case MethodRemoved = 'method.removed';
    case MethodRenamed = 'method.renamed';
    case MethodDefaultChanged = 'method.default_changed';

    case ChallengeIssued = 'challenge.issued';
    case ChallengeSucceeded = 'challenge.succeeded';
    case ChallengeFailed = 'challenge.failed';
    case ChallengeLockedOut = 'challenge.locked_out';

    case RecoveryCodeConsumed = 'recovery_code.consumed';
    case RecoveryCodesRegenerated = 'recovery_code.regenerated';

    case ReplayDetected = 'replay.detected';
    case CounterRegression = 'replay.counter_regression';

    case StepUpGranted = 'step_up.granted';
    case StepUpDenied = 'step_up.denied';

    case TrustedDeviceRegistered = 'trusted_device.registered';
    case TrustedDeviceUsed = 'trusted_device.used';
    case TrustedDeviceRevoked = 'trusted_device.revoked';

    case EnforcementBlocked = 'enforcement.blocked';
    case ReminderSnoozed = 'enforcement.reminder_snoozed';

    case OtpSent = 'otp.sent';
    case OtpSendThrottled = 'otp.send_throttled';

    case AdminReset = 'admin.reset';
    case AdminReminderSent = 'admin.reminder_sent';
    case SettingChanged = 'admin.setting_changed';
    case EnforcementPaused = 'admin.enforcement_paused';
    case EnforcementResumed = 'admin.enforcement_resumed';
    case AdminExempted = 'admin.exempted';

    /**
     * What the event is called for somebody reading the log.
     *
     * The stored value is a stable machine string — it goes in the database and
     * must never move — so the words a human reads live here rather than being
     * derived from it.
     */
    public function label(): string
    {
        return match ($this) {
            self::MethodEnrolled => __('Method added'),
            self::MethodConfirmed => __('Method confirmed'),
            self::MethodRemoved => __('Method removed'),
            self::MethodRenamed => __('Method renamed'),
            self::MethodDefaultChanged => __('Default method changed'),
            self::ChallengeIssued => __('Challenge sent'),
            self::ChallengeSucceeded => __('Signed in'),
            self::ChallengeFailed => __('Failed attempt'),
            self::ChallengeLockedOut => __('Locked out'),
            self::RecoveryCodeConsumed => __('Recovery code used'),
            self::RecoveryCodesRegenerated => __('Recovery codes regenerated'),
            self::ReplayDetected => __('Replayed code detected'),
            self::CounterRegression => __('Passkey counter went backwards'),
            self::StepUpGranted => __('Step-up granted'),
            self::StepUpDenied => __('Step-up denied'),
            self::TrustedDeviceRegistered => __('Device trusted'),
            self::TrustedDeviceUsed => __('Trusted device used'),
            self::TrustedDeviceRevoked => __('Trusted device revoked'),
            self::EnforcementBlocked => __('Blocked at the door'),
            self::ReminderSnoozed => __('Reminder dismissed'),
            self::OtpSent => __('Email code sent'),
            self::OtpSendThrottled => __('Email code throttled'),
            self::AdminReset => __('Reset by an administrator'),
            self::AdminExempted => __('Exempted by an administrator'),
            self::AdminReminderSent => __('Reminder sent by an administrator'),
            self::SettingChanged => __('Setting changed'),
            self::EnforcementPaused => __('Enforcement paused'),
            self::EnforcementResumed => __('Enforcement resumed'),
        };
    }

    /**
     * Events an administrator caused, as opposed to ones users generated.
     *
     * The default view of the log: a settings history that also lists every
     * login and every emailed code is a settings history nobody can read.
     *
     * @return array<int, self>
     */
    public static function adminActions(): array
    {
        return [
            self::SettingChanged,
            self::EnforcementPaused,
            self::EnforcementResumed,
            self::AdminReset,
            self::AdminExempted,
            self::AdminReminderSent,
        ];
    }

    /**
     * Events that should stand out in the activity log: they either indicate an
     * attack in progress or a change nobody should make by accident.
     */
    public function isSuspicious(): bool
    {
        return in_array($this, [
            self::ChallengeLockedOut,
            self::ReplayDetected,
            self::CounterRegression,
            self::RecoveryCodeConsumed,
            self::AdminReset,
            // Standing enforcement down is the one settings change that leaves
            // every account unprotected while it lasts.
            self::EnforcementPaused,
        ], true);
    }
}
