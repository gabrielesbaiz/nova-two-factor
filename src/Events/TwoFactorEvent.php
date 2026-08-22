<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Events;

use Gabrielesbaiz\NovaTwoFactor\Contracts\AuditableEvent;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Base class for everything the package dispatches.
 *
 * One shape means a single listener can write the whole audit trail, and host
 * apps can subscribe to {@see AuditableEvent} to observe all of it at once.
 */
abstract class TwoFactorEvent implements AuditableEvent
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly ?Authenticatable $user = null,
        public readonly ?TwoFactorMethod $method = null,
        public readonly array $context = [],
    ) {}

    /**
     * Whether this event reaches the audit table. Overridden by the ones that
     * are only interesting in aggregate.
     */
    public function shouldAudit(): bool
    {
        return true;
    }

    public function auditUser(): ?Authenticatable
    {
        return $this->user;
    }

    public function auditMethod(): ?TwoFactorMethod
    {
        return $this->method;
    }

    /**
     * @return array<string, mixed>
     */
    public function auditContext(): array
    {
        return $this->context;
    }
}
