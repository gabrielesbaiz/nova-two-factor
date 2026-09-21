<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Events;

use Gabrielesbaiz\NovaTwoFactor\Enums\AuditEvent;

/**
 * A user put the enrollment reminder away.
 *
 * Recorded because it is the only measure of whether `encouraged` is working.
 * The snooze itself lives in the cache — the right store for a countdown, and
 * the wrong one for a figure, since counting cache keys means walking every
 * user. One audit row at the moment of the decision makes the count a query.
 */
class ReminderSnoozed extends TwoFactorEvent
{
    public function auditEvent(): AuditEvent
    {
        return AuditEvent::ReminderSnoozed;
    }
}
