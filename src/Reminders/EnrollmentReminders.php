<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Reminders;

use Gabrielesbaiz\NovaTwoFactor\Enums\AuditEvent;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

/**
 * How often one person may be chased about enrolling.
 *
 * Keyed on the recipient rather than the sender, and enforced here rather than
 * at either call site, because both of them mail the same person: the row menu
 * on the compliance dashboard and the bulk Nova action. A limiter on the route
 * caps how fast one administrator can ask; it does nothing about the same
 * address being mailed by several of them, or by one of them all afternoon.
 *
 * The audit log is the record of who was mailed and when, so there is no second
 * table to keep in step: the row this reads is the row the last send wrote.
 */
class EnrollmentReminders
{
    /**
     * Zero disables the cooldown, which is the pre-existing behaviour and a
     * legitimate choice for a small team that would rather coordinate by
     * talking to each other.
     */
    public function cooldownHours(): int
    {
        return max(0, (int) Config::get('nova-two-factor.enforcement.remind_cooldown_hours', 24));
    }

    /**
     * Whether this account was reminded inside the cooldown.
     *
     * Typed on the model rather than on `Authenticatable`: the lookup is by
     * morph type and key, and the bulk Nova action hands over whatever the
     * resource selected.
     */
    public function recentlyReminded(Model $user): bool
    {
        return $this->lastRemindedAt($user) !== null;
    }

    /**
     * When the last reminder inside the cooldown went out, if one did.
     */
    public function lastRemindedAt(Model $user): ?Carbon
    {
        $hours = $this->cooldownHours();

        if ($hours === 0) {
            return null;
        }

        $sent = TwoFactorAudit::query()
            ->where('event', AuditEvent::AdminReminderSent->value)
            ->where('authenticatable_type', $user->getMorphClass())
            ->where('authenticatable_id', $user->getKey())
            ->where('created_at', '>=', now()->subHours($hours))
            ->latest('created_at')
            ->value('created_at');

        return $sent instanceof Carbon ? $sent : null;
    }
}
