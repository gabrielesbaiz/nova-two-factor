<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Nova\Filters;

use Gabrielesbaiz\NovaTwoFactor\Enums\AuditEvent;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Nova\Filters\Filter;
use Laravel\Nova\Http\Requests\NovaRequest;

/**
 * One specific event, for when you already know what you are looking for.
 */
class AuditEventType extends Filter
{
    public $component = 'select-filter';

    public $name;

    public function __construct()
    {
        $this->name = __('Event');
    }

    public function key(): string
    {
        return 'two-factor-event-type';
    }

    /**
     * @param  Builder<\Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorAudit>  $query
     */
    public function apply(NovaRequest $request, $query, mixed $value): Builder
    {
        return $value === null || $value === ''
            ? $query
            : $query->where('event', $value);
    }

    /**
     * @return array<string, string>
     */
    public function options(NovaRequest $request): array
    {
        $options = [];

        foreach (AuditEvent::cases() as $event) {
            $options[$event->label()] = $event->value;
        }

        // Alphabetical by what the reader sees, not by the enum's own order:
        // this is a list somebody scans for a phrase.
        ksort($options);

        return $options;
    }
}
