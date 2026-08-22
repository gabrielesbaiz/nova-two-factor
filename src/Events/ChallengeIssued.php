<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Events;

use Gabrielesbaiz\NovaTwoFactor\Enums\AuditEvent;

class ChallengeIssued extends TwoFactorEvent
{
    public function auditEvent(): AuditEvent
    {
        return AuditEvent::ChallengeIssued;
    }
}
