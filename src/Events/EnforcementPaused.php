<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Events;

use Gabrielesbaiz\NovaTwoFactor\Enums\AuditEvent;

/**
 * Enforcement was stood down for a while.
 *
 * Audited as suspicious, because it is: for the length of the pause every
 * account in the panel is protected by a password alone, and a pause nobody can
 * explain is indistinguishable from an attacker who reached the settings page.
 */
class EnforcementPaused extends TwoFactorEvent
{
    public function auditEvent(): AuditEvent
    {
        return AuditEvent::EnforcementPaused;
    }
}
