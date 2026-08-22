<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Console;

use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorAudit;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorChallenge;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Gabrielesbaiz\NovaTwoFactor\TrustedDevices\TrustedDeviceManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class PruneCommand extends Command
{
    protected $signature = 'nova-two-factor:prune {--days= : Override the configured audit retention}';

    protected $description = 'Remove expired challenges, trusted devices, abandoned enrollments and old audit rows';

    public function handle(TrustedDeviceManager $devices): int
    {
        // Spent and expired one-time codes. No reason to keep a used credential.
        $challenges = TwoFactorChallenge::query()
            ->where(fn ($query) => $query->whereNotNull('consumed_at')->orWhere('expires_at', '<', now()))
            ->delete();

        $expiredDevices = $devices->pruneExpired();

        // Enrollments started and never finished. Their secrets live in the
        // cache, not here, but the rows would otherwise accumulate forever and
        // muddy the "who is enrolled" question.
        $ttl = (int) Config::get('nova-two-factor.methods.totp.enrollment_ttl', 900);
        $abandoned = TwoFactorMethod::query()
            ->pending()
            ->where('created_at', '<', now()->subSeconds(max($ttl, 3600)))
            ->delete();

        $retention = (int) ($this->option('days') ?? Config::get('nova-two-factor.audit.prune_after_days', 365));

        $audits = $retention > 0
            ? TwoFactorAudit::query()->where('created_at', '<', now()->subDays($retention))->delete()
            : 0;

        $this->table(['Removed', 'Count'], [
            ['Challenges', $challenges],
            ['Trusted devices', $expiredDevices],
            ['Abandoned enrollments', $abandoned],
            ['Audit rows', $audits],
        ]);

        return self::SUCCESS;
    }
}
