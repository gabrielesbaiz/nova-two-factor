<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Listeners;

use Gabrielesbaiz\NovaTwoFactor\Contracts\AuditableEvent;
use Gabrielesbaiz\NovaTwoFactor\Jobs\RecordTwoFactorAudit;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorAudit;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;

/**
 * Turns every dispatched event into one audit row.
 *
 * Deliberately *not* a `ShouldQueue` listener. Laravel treats a listener whose
 * `shouldQueue()` returns false as one to skip entirely, not one to run
 * synchronously — so using that hook to mean "queue this only sometimes" drops
 * the audit trail on the floor. Instead the row is always built here, in the
 * request that produced it, and only the write is handed to the queue.
 */
class WriteAuditLog
{
    public function handle(AuditableEvent $event): void
    {
        if (! Config::get('nova-two-factor.audit.enabled', true) || ! $event->shouldAudit()) {
            return;
        }

        $user = $event->auditUser();
        $context = $event->auditContext();

        $attributes = [
            'event' => $event->auditEvent(),
            'method_type' => $event->auditMethod()?->type->value ?? ($context['method_type'] ?? null),
            'method_id' => $event->auditMethod()?->getKey(),
            'authenticatable_type' => $user?->getMorphClass(),
            'authenticatable_id' => $user?->getAuthIdentifier(),
            'ip' => Request::ip(),
            'user_agent' => Request::userAgent() === null ? null : mb_substr((string) Request::userAgent(), 0, 1000),
            'context' => $context === [] ? null : $context,
            'created_at' => now(),
        ];

        if (Config::get('nova-two-factor.audit.queue', false)) {
            RecordTwoFactorAudit::dispatch($attributes);

            return;
        }

        TwoFactorAudit::query()->create($attributes);
    }
}
