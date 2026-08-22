<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Nova\Metrics;

use Gabrielesbaiz\NovaTwoFactor\Enums\AuditEvent;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorAudit;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Trend;
use Laravel\Nova\Metrics\TrendResult;

/**
 * Failed challenges over time — the security-monitoring signal.
 *
 * A flat line near zero with a sudden spike is what credential stuffing looks
 * like from the inside.
 */
class TwoFactorFailures extends Trend
{
    public $name;

    public function __construct()
    {
        parent::__construct();

        $this->name = __('Failed two-factor attempts');
    }

    public function calculate(NovaRequest $request): TrendResult
    {
        return $this->countByDays(
            $request,
            TwoFactorAudit::query()->whereIn('event', [
                AuditEvent::ChallengeFailed->value,
                AuditEvent::ChallengeLockedOut->value,
                AuditEvent::ReplayDetected->value,
            ]),
            'created_at',
        )->showLatestValue();
    }

    /**
     * @return array<int|string, string>
     */
    public function ranges(): array
    {
        return [
            7 => __('7 days'),
            30 => __('30 days'),
            60 => __('60 days'),
        ];
    }

    public function uriKey(): string
    {
        return 'two-factor-failures';
    }
}
