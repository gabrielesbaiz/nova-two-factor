<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Jobs;

use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorAudit;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Writes one audit row.
 *
 * Takes a flat, already-resolved payload rather than the event object: an audit
 * record must not carry an Eloquent model or an Authenticatable through the
 * queue, where it would be re-fetched (or fail to be) long after the fact.
 */
class RecordTwoFactorAudit implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(private readonly array $attributes) {}

    public function handle(): void
    {
        TwoFactorAudit::query()->create($this->attributes);
    }
}
