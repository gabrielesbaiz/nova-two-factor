<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Events;

use Gabrielesbaiz\NovaTwoFactor\Enums\AuditEvent;

/**
 * An administrator asked a user to enrol.
 *
 * Audited like the reset it is meant to avoid: a user who receives an
 * unexpected "set up two-factor" mail should be able to have someone establish
 * that it came from their own administrator and not from an attacker.
 */
class EnrollmentReminderSent extends TwoFactorEvent
{
    public function auditEvent(): AuditEvent
    {
        return AuditEvent::AdminReminderSent;
    }
}
