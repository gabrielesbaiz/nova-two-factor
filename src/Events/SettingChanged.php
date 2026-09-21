<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Events;

use Gabrielesbaiz\NovaTwoFactor\Enums\AuditEvent;

/**
 * A policy setting was changed from the admin page.
 *
 * The context carries the key and both values: "changed to required" is half a
 * sentence, and the half that matters in an incident is what it was before.
 */
class SettingChanged extends TwoFactorEvent
{
    public function auditEvent(): AuditEvent
    {
        return AuditEvent::SettingChanged;
    }
}
