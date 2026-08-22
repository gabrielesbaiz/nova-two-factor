<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Contracts;

use Gabrielesbaiz\NovaTwoFactor\Enums\AuditEvent;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Marks an event as belonging to the two-factor audit trail.
 *
 * This is an interface rather than a base class on purpose: Laravel's event
 * dispatcher resolves listeners through `class_implements()`, so a listener
 * registered against a parent *class* never fires for its subclasses. One
 * listener for the whole surface only works if the surface is an interface.
 */
interface AuditableEvent
{
    public function auditEvent(): AuditEvent;

    public function shouldAudit(): bool;

    public function auditUser(): ?Authenticatable;

    public function auditMethod(): ?TwoFactorMethod;

    /**
     * @return array<string, mixed>
     */
    public function auditContext(): array;
}
