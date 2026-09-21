<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Http\Controllers;

use Gabrielesbaiz\NovaTwoFactor\Enums\AuditEvent;
use Gabrielesbaiz\NovaTwoFactor\Enums\EnforcementMode;
use Gabrielesbaiz\NovaTwoFactor\Events\EnforcementPaused;
use Gabrielesbaiz\NovaTwoFactor\Events\EnforcementResumed;
use Gabrielesbaiz\NovaTwoFactor\Events\SettingChanged;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorAudit;
use Gabrielesbaiz\NovaTwoFactor\Settings\Pause;
use Gabrielesbaiz\NovaTwoFactor\Settings\SettingSchema;
use Gabrielesbaiz\NovaTwoFactor\Settings\SettingsRepository;
use Gabrielesbaiz\NovaTwoFactor\Support\AuditedModels;
use Gabrielesbaiz\NovaTwoFactor\Support\Enforcement;
use Gabrielesbaiz\NovaTwoFactor\Support\TwoFactorUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Laravel\Nova\Nova;

/**
 * Policy an administrator can change without a deploy.
 *
 * Reads are allowed wherever the admin gate is: seeing the current policy, and
 * where each value comes from, is useful on its own. Writes additionally need
 * `settings.editable`, which is off by default — a fresh install should not
 * hand anyone who reaches Nova the ability to weaken two-factor policy.
 */
class SettingsController extends Controller
{
    public function __construct(
        protected SettingsRepository $settings,
        protected Pause $pause,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeSettings($request);

        return response()->json([
            'editable' => $this->editable(),
            'pause' => $this->pause->state(),
            'pause_max_minutes' => (int) Config::get('nova-two-factor.settings.pause_max_minutes', 120),
            'enabled' => (bool) Config::get('nova-two-factor.enabled', true),
            'sections' => $this->sections(),
            'impact' => $this->impact(),
            'history' => $this->history(),
            // Where the rest of it lives. A preview answers "did something just
            // change?"; the archive answers "what happened in March?", and
            // Nova's own index already solved paging, filtering and deep links.
            'history_url' => $this->novaPath('resources/two-factor-audits'),
        ]);
    }

    /**
     * Write one or more settings.
     *
     * Per key rather than as a blob: two administrators saving different
     * sections would otherwise overwrite each other, and the audit row beside
     * each change can name exactly what moved.
     */
    public function update(Request $request): JsonResponse
    {
        $this->authorizeSettings($request, write: true);

        $validated = $request->validate([
            'settings' => ['required', 'array'],
            'settings.*' => ['nullable'],
        ]);

        $actor = Nova::user($request) ?? $request->user();
        $changed = [];

        $this->guardAgainstNoMethods($validated['settings']);

        foreach ($validated['settings'] as $key => $value) {
            if (! SettingSchema::has($key)) {
                abort(422, __('That setting cannot be changed here.'));
            }

            $value = $this->cast($key, $value);
            $previous = $this->settings->set($key, $value, $actor);

            if ($previous === $value) {
                continue;
            }

            $changed[$key] = ['from' => $previous, 'to' => $value];

            Event::dispatch(new SettingChanged(null, context: [
                'key' => $key,
                'from' => is_scalar($previous) || $previous === null ? $previous : null,
                'to' => is_scalar($value) || $value === null ? $value : null,
                'by' => $actor?->getAuthIdentifier(),
            ]));
        }

        return response()->json([
            'message' => $changed === []
                ? __('Nothing changed.')
                : trans_choice('{1} :count setting saved.|[2,*] :count settings saved.', count($changed), ['count' => count($changed)]),
            'sections' => $this->sections(),
            'impact' => $this->impact(),
            'history' => $this->history(),
        ]);
    }

    public function pause(Request $request): JsonResponse
    {
        $this->authorizeSettings($request, write: true);

        $ceiling = (int) Config::get('nova-two-factor.settings.pause_max_minutes', 120);

        $validated = $request->validate([
            'minutes' => ['required', 'integer', 'min:1', 'max:'.$ceiling],
            // A reason is not optional. A pause nobody can explain afterwards is
            // indistinguishable from an attacker who reached this page.
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $actor = Nova::user($request) ?? $request->user();
        $until = $this->pause->start($validated['minutes'], $validated['reason'], $actor);

        Event::dispatch(new EnforcementPaused(null, context: [
            'until' => $until->toIso8601String(),
            'minutes' => $validated['minutes'],
            'reason' => $validated['reason'],
            'by' => $actor?->getAuthIdentifier(),
        ]));

        return response()->json([
            'message' => __('Enforcement paused until :time.', ['time' => $until->format('H:i')]),
            'pause' => $this->pause->state(),
        ]);
    }

    public function resume(Request $request): JsonResponse
    {
        $this->authorizeSettings($request, write: true);

        $actor = Nova::user($request) ?? $request->user();

        $this->pause->resume();

        Event::dispatch(new EnforcementResumed(null, context: ['by' => $actor?->getAuthIdentifier()]));

        return response()->json([
            'message' => __('Enforcement resumed.'),
            'pause' => $this->pause->state(),
        ]);
    }

    /**
     * Refuse a save that would leave no way to enrol.
     *
     * Turning off the last method does not disable two-factor — it leaves the
     * policy in force with nothing to satisfy it, so under `required` every
     * user is locked out of Nova with no route back except an administrator
     * who is also locked out. Checked against the state the save would produce
     * rather than the current one, because the three switches arrive together.
     *
     * @param  array<string, mixed>  $incoming
     */
    protected function guardAgainstNoMethods(array $incoming): void
    {
        $remaining = 0;

        foreach (SettingSchema::methodKeys() as $key) {
            $value = match (true) {
                // Not mentioned: stays as it is.
                ! array_key_exists($key, $incoming) => (bool) Config::get('nova-two-factor.'.$key, false),
                // Cleared, which means "fall back to the default" — not "off".
                // Reading null as false made "restore defaults" refuse itself.
                $incoming[$key] === null => (bool) $this->settings->baseline($key),
                default => filter_var($incoming[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
            };

            $remaining += $value ? 1 : 0;
        }

        abort_if($remaining === 0, 422, __('At least one method has to stay switched on.'));
    }

    /**
     * Every editable setting, with its value, its type and whether the
     * environment has taken it out of the administrator's hands.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function sections(): array
    {
        $sections = [];

        foreach (SettingSchema::all() as $key => $definition) {
            $state = $this->settings->state($key);

            $sections[$definition['section']][] = [
                'key' => $key,
                'type' => $definition['type'],
                'value' => $this->present($key, $state['value']),
                'stored' => $state['stored'],
                // What the file says, and whether the panel is currently
                // disagreeing with it. Not a warning: an explanation for
                // whoever reads `.env` later and finds it out of date.
                'from_env' => $state['from_env'],
                'overrides_env' => $state['overrides_env'],
                'env_value' => $this->present($key, $state['env_value']),
                'options' => $definition['options'] ?? null,
                // Named server-side: the mode names live in a namespaced
                // catalogue precisely so a host cannot rename them, and a
                // second list in JS would walk straight back into that.
                'option_labels' => $this->optionLabels($key, $definition['options'] ?? null),
                // Null means "always relevant"; a list means the setting only
                // does anything in those modes.
                'modes' => $definition['modes'] ?? null,
                // Other settings this one only makes sense alongside.
                'requires' => $definition['requires'] ?? null,
                'min' => $definition['min'] ?? null,
                'max' => $definition['max'] ?? null,
            ];
        }

        return $sections;
    }

    /**
     * What switching to `required` would cost, right now.
     *
     * The sentence that has to appear before the save, not after it: on a panel
     * where most administrators have no second factor, the obvious click is one
     * that locks the person making it out of the panel.
     *
     * @return array<string, mixed>
     */
    protected function impact(): array
    {
        $enforcement = app(Enforcement::class);

        $without = 0;
        $total = 0;

        AuditedModels::each(
            static function (Model $model) use (&$without, &$total): void {
                if (TwoFactorUser::tryFrom($model) === null) {
                    return;
                }

                $total++;

                if (($model->getRelationValue('twoFactorMethods')?->count() ?? 0) === 0) {
                    $without++;
                }
            },
            static fn (Builder $query): Builder => $query->with([
                'twoFactorMethods' => static fn ($methods) => $methods->whereNotNull('confirmed_at'),
            ]),
        );

        return [
            'in_scope' => $total,
            'without_factor' => $without,
            'grace_days' => (int) Config::get('nova-two-factor.enforcement.grace_days', 0),
            'mode' => $enforcement->mode()->value,
        ];
    }

    /**
     * Recent settings changes, in the words an administrator would use.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function history(int $limit = 5): array
    {
        return TwoFactorAudit::query()
            ->whereIn('event', [
                AuditEvent::SettingChanged->value,
                AuditEvent::EnforcementPaused->value,
                AuditEvent::EnforcementResumed->value,
            ])
            ->latest('created_at')
            ->limit($limit)
            ->get(['event', 'context', 'created_at'])
            ->map(fn ($audit): array => [
                'event' => $audit->event instanceof AuditEvent ? $audit->event->value : (string) $audit->event,
                'context' => $audit->getAttribute('context') ?? [],
                'by' => $this->nameOf($audit->getAttribute('context')['by'] ?? null),
                'at' => $audit->getAttribute('created_at')?->toIso8601String(),
            ])
            ->all();
    }

    protected function nameOf(mixed $id): ?string
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

    /**
     * Coerce to the declared type, and refuse anything outside its bounds.
     *
     * The bounds are not cosmetic: a step-up window of a day, or an email code
     * good for an hour, are both "valid integers" and both quietly remove the
     * protection the setting exists to provide.
     */
    protected function cast(string $key, mixed $value): mixed
    {
        $definition = SettingSchema::get($key) ?? [];

        if ($value === null || $value === '') {
            return null;
        }

        return match ($definition['type'] ?? 'string') {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false,
            'int' => $this->boundedInt($value, $definition),
            'enum' => $this->oneOf($value, $definition['options'] ?? []),
            'date' => $this->date($value),
            default => (string) $value,
        };
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    protected function boundedInt(mixed $value, array $definition): int
    {
        $number = (int) $value;

        abort_if(
            $number < ($definition['min'] ?? PHP_INT_MIN) || $number > ($definition['max'] ?? PHP_INT_MAX),
            422,
            __('That value is outside the range this setting allows.'),
        );

        return $number;
    }

    /**
     * @param  array<int, string>  $options
     */
    protected function oneOf(mixed $value, array $options): string
    {
        abort_unless(in_array($value, $options, true), 422, __('That is not one of the allowed values.'));

        return (string) $value;
    }

    protected function date(mixed $value): string
    {
        validator(['date' => $value], ['date' => ['date_format:Y-m-d']])->validate();

        return (string) $value;
    }

    /**
     * @param  array<int, string>|null  $options
     * @return array<string, string>|null
     */
    protected function optionLabels(string $key, ?array $options): ?array
    {
        if ($options === null) {
            return null;
        }

        $labels = [];

        foreach ($options as $option) {
            $labels[$option] = match ($key) {
                'enforcement.mode' => EnforcementMode::from($option)->label(),
                'enforcement.grace_mode' => $option === 'date'
                    ? __('A shared deadline')
                    : __('Days per account'),
                default => $option,
            };
        }

        return $labels;
    }

    protected function present(string $key, mixed $value): mixed
    {
        if ($key === 'enforcement.mode' && $value instanceof EnforcementMode) {
            return $value->value;
        }

        return $value;
    }

    protected function editable(): bool
    {
        return (bool) Config::get('nova-two-factor.settings.editable', false);
    }

    protected function authorizeSettings(Request $request, bool $write = false): void
    {
        $this->novaUserOrFail();

        $gate = Config::get('nova-two-factor.nova.admin_gate');

        if (is_string($gate) && $gate !== '') {
            abort_unless(Gate::forUser($this->novaUser())->allows($gate), 403);
        }

        // Reading the policy is useful even where changing it is not allowed,
        // so only writes are gated on the switch.
        abort_if($write && ! $this->editable(), 403, __('Changing settings from Nova is turned off.'));
    }
}
