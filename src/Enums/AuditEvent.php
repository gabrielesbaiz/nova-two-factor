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

    case OtpSent = 'otp.sent';
    case OtpSendThrottled = 'otp.send_throttled';

    case AdminReset = 'admin.reset';
    case AdminExempted = 'admin.exempted';

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
        ], true);
    }
}
