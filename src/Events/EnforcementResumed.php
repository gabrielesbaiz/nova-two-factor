<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Events;

use Gabrielesbaiz\NovaTwoFactor\Enums\AuditEvent;

/**
 * Enforcement came back on — by hand, before the pause expired.
 *
 * Worth its own row so the audit log shows the window that was actually open,
 * rather than the one that was originally asked for.
 */
class EnforcementResumed extends TwoFactorEvent
{
    public function auditEvent(): AuditEvent
    {
        return AuditEvent::EnforcementResumed;
    }
}
