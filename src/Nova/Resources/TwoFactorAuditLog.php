<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Nova\Resources;

use Gabrielesbaiz\NovaTwoFactor\Enums\AuditEvent;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorAudit;
use Gabrielesbaiz\NovaTwoFactor\Nova\Filters\AuditEventGroup;
use Gabrielesbaiz\NovaTwoFactor\Nova\Filters\AuditEventType;
use Gabrielesbaiz\NovaTwoFactor\Settings\SettingSchema;
use Gabrielesbaiz\NovaTwoFactor\Support\AuditedModels;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Laravel\Nova\Fields\Badge;
use Laravel\Nova\Fields\Code;
use Laravel\Nova\Fields\DateTime;
use Laravel\Nova\Fields\MorphTo;
use Laravel\Nova\Fields\Text;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Resource;

/**
 * The audit trail, as a Nova resource.
 *
 * A resource rather than a table inside a card: paging, filtering, sorting and
 * deep links are the whole problem with a log, and Nova has already solved all
 * four. The settings page keeps a five-row preview and links here for the rest
 * — a preview answers "did something just change?", an archive answers "what
 * happened in March?", and they are different questions.
 *
 * Read-only in every direction. An audit trail an administrator can edit is not
 * an audit trail, and one they can delete is worse than none: it reads as
 * evidence while being able to lie.
 */
class TwoFactorAuditLog extends Resource
{
    /** @var class-string<TwoFactorAudit> */
    public static $model = TwoFactorAudit::class;

    public static $title = 'id';

    /** @var array<int, string> */
    public static $search = ['ip'];

    public static $displayInNavigation = false;

    public static $globallySearchable = false;

    public static function label(): string
    {
        return __('2FA activity');
    }

    public static function singularLabel(): string
    {
        return __('2FA event');
    }

    public static function uriKey(): string
    {
        return 'two-factor-audits';
    }

    /**
     * Newest first, and never N+1 the owner column.
     */
    /**
     * @param  Builder<TwoFactorAudit>  $query
     * @return Builder<TwoFactorAudit>
     */
    public static function indexQuery(NovaRequest $request, $query): Builder
    {
        return $query->with('authenticatable')->latest('created_at');
    }

    /**
     * @return array<int, \Laravel\Nova\Fields\Field>
     */
    public function fields(NovaRequest $request): array
    {
        return [
            DateTime::make(__('When'), 'created_at')->sortable()->filterable(),

            // The stored value drives both the colour and the words. Resolving
            // it to a badge *type* first made every row read "WARNING", which
            // is the styling talking rather than the event.
            Badge::make(__('Event'), 'event')
                ->map($this->badgeTypes())
                ->labels($this->eventLabels())
                ->sortable(),

            // Plain text, not a MorphTo: the owner may be a model with no Nova
            // resource of its own, and Nova renders that as a red unresolvable
            // link — which reads as an error beside an event that is fine.
            Text::make(__('User'), fn (): string => $this->ownerName())->onlyOnIndex(),

            MorphTo::make(__('User'), 'authenticatable')
                ->types($this->ownerResources())
                ->nullable()
                ->onlyOnDetail(),

            // The one line that says what actually happened, built from the
            // context rather than making an administrator read raw JSON.
            Text::make(__('Detail'), fn (): string => $this->detail())->onlyOnIndex(),

            Text::make(__('IP'), 'ip')->sortable(),

            Code::make(__('Context'), 'context')->json()->onlyOnDetail(),

            Text::make(__('Browser'), 'user_agent')->onlyOnDetail(),
        ];
    }

    /**
     * @return array<int, \Laravel\Nova\Filters\Filter>
     */
    public function filters(NovaRequest $request): array
    {
        return [
            // Defaults to administrator actions: a settings history that also
            // lists every login and emailed code is one nobody can read.
            new AuditEventGroup,
            new AuditEventType,
        ];
    }

    public static function authorizedToViewAny(Request $request): bool
    {
        $gate = Config::get('nova-two-factor.nova.admin_gate');

        if (! is_string($gate) || $gate === '') {
            return true;
        }

        $user = \Laravel\Nova\Nova::user($request);

        return $user !== null && Gate::forUser($user)->allows($gate);
    }

    public function authorizedToView(Request $request): bool
    {
        return static::authorizedToViewAny($request);
    }

    public static function authorizedToCreate(Request $request): bool
    {
        return false;
    }

    public function authorizedToUpdate(Request $request): bool
    {
        return false;
    }

    public function authorizedToDelete(Request $request): bool
    {
        return false;
    }

    public function authorizedToReplicate(Request $request): bool
    {
        return false;
    }

    /**
     * A sentence per event, from the context it carries.
     */
    protected function detail(): string
    {
        $context = $this->resource->getAttribute('context') ?? [];
        $event = $this->resource->getAttribute('event');
        $by = isset($context['by']) ? $this->actorName($context['by']) : null;

        return match (true) {
            // A settings change, in the words the settings page uses.
            $event === AuditEvent::SettingChanged => __(':setting: :from → :to', [
                'setting' => SettingSchema::label((string) ($context['key'] ?? '')),
                'from' => $this->scalar($context['from'] ?? null),
                'to' => $this->scalar($context['to'] ?? null),
            ]).($by === null ? '' : ' · '.__('by :name', ['name' => $by])),

            $event === AuditEvent::EnforcementPaused => __('Paused for :count minutes', [
                'count' => (string) ($context['minutes'] ?? '?'),
            ]).($context['reason'] ?? null ? ' — “'.$context['reason'].'”' : ''),

            $event === AuditEvent::EnforcementResumed => $by === null
                ? __('Resumed by hand')
                : __('Resumed by :name', ['name' => $by]),

            // The three that happen *to* somebody: the sentence has to name
            // both sides, or a list of them says only that something happened.
            $event === AuditEvent::AdminReminderSent => __('Reminder sent to :user', ['user' => $this->ownerName()])
                .($by === null ? '' : ' '.__('by :name', ['name' => $by]))
                .($context['note'] ?? null ? ' — “'.$context['note'].'”' : ''),

            $event === AuditEvent::AdminReset => __('Two-factor reset for :user', ['user' => $this->ownerName()])
                .($by === null ? '' : ' '.__('by :name', ['name' => $by]))
                .($context['reason'] ?? null ? ' — “'.$context['reason'].'”' : ''),

            $event === AuditEvent::AdminExempted => __('Exempted :user', ['user' => $this->ownerName()])
                .($by === null ? '' : ' '.__('by :name', ['name' => $by])),

            default => (string) ($context['method_type'] ?? $context['reason'] ?? ''),
        };
    }

    /**
     * The account an event happened to.
     */
    protected function ownerName(): string
    {
        $owner = $this->resource->getRelationValue('authenticatable');

        return (string) ($owner?->getAttribute('name') ?? $owner?->getAttribute('email') ?? __('Unknown'));
    }

    protected function actorName(mixed $id): ?string
    {
        if ($id === null) {
            return null;
        }

        foreach (array_keys(AuditedModels::all()) as $model) {
            $found = $model::query()->find($id);

            if ($found !== null) {
                return (string) ($found->getAttribute('name') ?? $found->getAttribute('email') ?? $id);
            }
        }

        return (string) $id;
    }

    protected function scalar(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? __('On') : __('Off'),
            default => (string) $value,
        };
    }

    /**
     * Every event value mapped to its badge colour.
     *
     * @return array<string, string>
     */
    protected function badgeTypes(): array
    {
        $types = [];

        foreach (AuditEvent::cases() as $event) {
            $types[$event->value] = match (true) {
                $event->isSuspicious() => 'danger',
                in_array($event, AuditEvent::adminActions(), true) => 'warning',
                default => 'info',
            };
        }

        return $types;
    }

    /**
     * @return array<string, string>
     */
    protected function eventLabels(): array
    {
        $labels = [];

        foreach (AuditEvent::cases() as $event) {
            $labels[$event->value] = $event->label();
        }

        return $labels;
    }

    /**
     * Resources the owner column may point at.
     *
     * Empty is fine: Nova renders the morph as plain text, which still names
     * the row, rather than erroring on a model with no resource of its own.
     *
     * @return array<int, class-string<resource>>
     */
    protected function ownerResources(): array
    {
        return array_values(array_filter(array_map(
            static fn (string $model): ?string => \Laravel\Nova\Nova::resourceForModel($model),
            array_keys(AuditedModels::all()),
        )));
    }
}
