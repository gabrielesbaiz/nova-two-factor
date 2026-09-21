<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Nova\Filters;

use Gabrielesbaiz\NovaTwoFactor\Enums\AuditEvent;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * Which slice of the log to read.
 *
 * Defaults to administrator actions rather than everything, because the log is
 * dominated by routine user events — every sign-in, every emailed code — and a
 * settings history buried in them is a settings history nobody reads. The other
 * options are one click away.
 */
class AuditEventGroup extends Filter
{
    public $component = 'select-filter';

    public $name;

    public function __construct()
    {
        $this->name = __('Show');
    }

    public function key(): string
    {
        return 'two-factor-event-group';
    }

    /**
     * @param  Builder<\Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorAudit>  $query
     */
    public function apply(NovaRequest $request, $query, mixed $value): Builder
    {
        return match ($value) {
            'admin' => $query->whereIn('event', array_map(
                static fn (AuditEvent $event): string => $event->value,
                AuditEvent::adminActions(),
            )),
            'suspicious' => $query->whereIn('event', array_map(
                static fn (AuditEvent $event): string => $event->value,
                array_filter(AuditEvent::cases(), static fn (AuditEvent $event): bool => $event->isSuspicious()),
            )),
            default => $query,
        };
    }

    /**
     * @return array<string, string>
     */
    public function options(NovaRequest $request): array
    {
        return [
            __('Administrator actions') => 'admin',
            __('Worth a second look') => 'suspicious',
            __('Everything') => 'all',
        ];
    }

    public function default(): string
    {
        return 'admin';
    }
}
