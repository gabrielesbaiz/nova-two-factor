<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Events;

use Gabrielesbaiz\NovaTwoFactor\Enums\AuditEvent;

class OtpSent extends TwoFactorEvent
{
    public function auditEvent(): AuditEvent
    {
        return AuditEvent::OtpSent;
    }
}
