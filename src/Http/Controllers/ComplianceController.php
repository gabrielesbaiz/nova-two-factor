<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Http\Controllers;

use Gabrielesbaiz\NovaTwoFactor\Actions\ResetTwoFactor;
use Gabrielesbaiz\NovaTwoFactor\Enums\AuditEvent;
use Gabrielesbaiz\NovaTwoFactor\Enums\EnforcementMode;
use Gabrielesbaiz\NovaTwoFactor\Enums\MethodType;
use Gabrielesbaiz\NovaTwoFactor\Events\EnrollmentReminderSent;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorAudit;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorMethod;
use Gabrielesbaiz\NovaTwoFactor\Models\TwoFactorTrustedDevice;
use Gabrielesbaiz\NovaTwoFactor\Notifications\EnrollmentReminderNotification;
use Gabrielesbaiz\NovaTwoFactor\Notifications\TwoFactorResetNotification;
use Gabrielesbaiz\NovaTwoFactor\Reminders\EnrollmentReminders;
use Gabrielesbaiz\NovaTwoFactor\Settings\Pause;
use Gabrielesbaiz\NovaTwoFactor\Support\AuditedModels;
use Gabrielesbaiz\NovaTwoFactor\Support\Enforcement;
use Gabrielesbaiz\NovaTwoFactor\Support\PanelUrl;
use Gabrielesbaiz\NovaTwoFactor\Support\TwoFactorUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Laravel\Nova\Nova;

/**
 * The numbers behind the compliance dashboard.
 *
 * One pass over each audited population, because every figure on the page comes
 * from the same walk: the rollup, and the rows that need attention. Two passes
 * would be two chances for the table to disagree with the tiles above it.
 */
class ComplianceController extends Controller
{
    /**
     * How many rows the table holds.
     *
     * The table is not a user directory — Nova's own resource is — it is the
     * queue of people to chase. A cap keeps the response bounded on a hundred
     * thousand rows, and the tiles above always report the true totals, so a
     * truncated list can never be mistaken for the whole picture.
     */
    protected const ROWS = 100;

    /**
     * Owner names already resolved this request, keyed "type:id".
     *
     * @var array<string, string>
     */
    protected array $names = [];

    public function index(Request $request): JsonResponse
    {
        $this->authorizeCompliance($request);

        $enforcement = app(Enforcement::class);
        $search = trim((string) $request->query('search', ''));

        $counts = ['enrolled' => 0, 'grace' => 0, 'overdue' => 0, 'optional' => 0];
        $mix = [];
        $health = [
            'covered' => 0,
            'single_factor' => 0,
            'phishing_resistant' => 0,
            'no_recovery' => 0,
            'low_recovery' => 0,
            'stale' => 0,
        ];
        $enrolDays = [];
        $expiringToday = 0;
        $trustedOwners = $this->ownersWithTrustedDevices();
        $lockedOutOwners = $this->recentlyLockedOutOwners();
        $rows = [];

        AuditedModels::each(
            function (Model $model) use (&$counts, &$rows, &$expiringToday, &$mix, &$health, &$enrolDays, $enforcement, $trustedOwners, $lockedOutOwners): void {
                $user = TwoFactorUser::tryFrom($model);

                if ($user === null) {
                    return;
                }

                // Through the relation rather than the dynamic property: the
                // callback is typed to `Model`, and only the package's trait
                // puts `twoFactorMethods` on it.
                $methods = $model->getRelationValue('twoFactorMethods') ?? collect();
                $graceEndsAt = $enforcement->appliesTo($user) ? $enforcement->graceEndsAt($user) : null;

                $status = match (true) {
                    $methods->isNotEmpty() => 'enrolled',
                    ! $enforcement->appliesTo($user) => 'optional',
                    $graceEndsAt !== null && $graceEndsAt->isFuture() => 'grace',
                    default => 'overdue',
                };

                $counts[$status]++;

                // Counted from the same walk as the tiles rather than from the
                // methods table directly: a mix drawn from every row in the
                // database would describe a different population than the
                // percentage above it, and the two would quietly disagree.
                foreach ($methods as $method) {
                    $type = $method->type instanceof MethodType ? $method->type->value : (string) $method->type;
                    $mix[$type] = ($mix[$type] ?? 0) + 1;
                }

                // Enrolled is not the same as resilient, and the difference is
                // what the health figures are for. Scoped to a block rather than
                // an early return: everyone still has to reach the queue below,
                // and the people who matter most there are the ones who would
                // have been returned past.
                if ($status === 'enrolled') {
                    $health['covered']++;

                    if ($methods->count() === 1) {
                        $health['single_factor']++;
                    }

                    if ($methods->contains(static fn ($method): bool => $method->type === MethodType::WebAuthn)) {
                        $health['phishing_resistant']++;
                    }

                    $codes = (int) ($model->getAttribute('unused_recovery_codes') ?? 0);

                    if ($codes === 0) {
                        $health['no_recovery']++;
                    } elseif ($codes <= 3) {
                        $health['low_recovery']++;
                    }

                    $lastUsed = $methods->max('last_used_at');

                    if ($lastUsed === null || $lastUsed->lt(now()->subDays(90))) {
                        $health['stale']++;
                    }

                    // Time to enrol measures the onboarding process rather than
                    // the person, so it is dated from the account.
                    $firstConfirmed = $methods->min('confirmed_at');
                    $createdAt = $model->getAttribute('created_at');

                    if ($firstConfirmed !== null && $createdAt !== null) {
                        $enrolDays[] = (int) max(0, $createdAt->diffInDays($firstConfirmed));
                    }
                }

                if ($status === 'grace' && $graceEndsAt?->isToday()) {
                    $expiringToday++;
                }

                $flags = [];

                if ($status === 'enrolled') {
                    if ($methods->count() === 1) {
                        $flags[] = 'single_factor';
                    }

                    if ((int) ($model->getAttribute('unused_recovery_codes') ?? 0) <= 3) {
                        $flags[] = 'recovery';
                    }

                    $used = $methods->max('last_used_at');

                    if ($used === null || $used->lt(now()->subDays(90))) {
                        $flags[] = 'stale';
                    }
                }

                if (in_array($this->ownerKey($model), $trustedOwners, true)) {
                    $flags[] = 'devices';
                }

                if (in_array($this->ownerKey($model), $lockedOutOwners, true)) {
                    $flags[] = 'lockouts';
                }

                if (count($rows) < self::ROWS * 4) {
                    $rows[] = [
                        'id' => $model->getKey(),
                        // Which audited population this row came from. Sent back
                        // on an action and matched against the audited set, so a
                        // crafted request cannot name an arbitrary class.
                        'model' => $model::class,
                        'name' => (string) ($model->getAttribute('name') ?? $model->getAttribute('email') ?? '#'.$model->getKey()),
                        'email' => $model->getAttribute('email'),
                        'initials' => $this->initials($model),
                        'status' => $status,
                        'methods' => $methods->map(static fn ($method): array => [
                            'type' => $method->type instanceof MethodType ? $method->type->value : (string) $method->type,
                            'label' => $method->type instanceof MethodType ? $method->type->label() : (string) $method->type,
                        ])->values()->all(),
                        // Never used is the signal, so it is a null rather than
                        // a date the caller has to interpret as "not really".
                        'last_verified_at' => optional($methods->max('last_used_at'))->toIso8601String(),
                        'grace_ends_at' => $graceEndsAt?->toIso8601String(),
                        'resource_url' => $this->resourceUrl($model),
                        // Which resilience tiles this row belongs to, so
                        // clicking one filters the queue to the people it
                        // counts — a number nobody can act on is half a figure.
                        'flags' => $flags,
                    ];
                }
            },
            fn (Builder $query): Builder => $this->narrow($query, $search),
        );

        // Overdue first, then grace, then everyone else: the page is a queue of
        // people to chase, and sorting it by name would bury them.
        $order = ['overdue' => 0, 'grace' => 1, 'enrolled' => 2, 'optional' => 3];
        usort($rows, static fn (array $a, array $b): int => [$order[$a['status']], $a['name']] <=> [$order[$b['status']], $b['name']]);

        $inScope = array_sum($counts);
        $rows = array_slice($rows, 0, self::ROWS);

        return response()->json([
            'summary' => [
                'in_scope' => $inScope,
                'enrolled' => $counts['enrolled'],
                'enrolled_percent' => $inScope === 0 ? 0 : (int) round($counts['enrolled'] / $inScope * 100),
                'not_enrolled' => $inScope - $counts['enrolled'],
                'grace' => $counts['grace'],
                'overdue' => $counts['overdue'],
                'optional' => $counts['optional'],
                'expiring_today' => $expiringToday,
            ],
            'health' => $this->health($health, $enrolDays),
            'methods' => $this->mix($mix),
            'failures' => $this->failures(),
            'devices' => $this->devices(),
            'lockouts' => $this->lockouts(),
            'recovery_sign_ins' => $this->recoverySignIns(),
            'events' => $this->adminEvents(),
            'events_url' => $this->novaPath('resources/two-factor-audits'),
            'resistant_trend' => $this->resistantTrend(),
            'rows' => $rows,
            'truncated' => count($rows) < ($inScope - $counts['optional']) && count($rows) === self::ROWS,
            // Which families of figure are meaningful right now. The server
            // decides because the server knows the mode — and because a section
            // that is not sent is a section that is not computed: under
            // `optional` the grace-window pass does not run at all.
            'sections' => $this->sections($enforcement),
            'spotlight' => $this->spotlight($enforcement),
            'paused' => app(Pause::class)->state(),
            'mode' => [
                'value' => $enforcement->mode()->value,
                'label' => $enforcement->mode()->label(),
                'summary' => $enforcement->mode()->summary(),
                'blocks' => $enforcement->mode()->blocks(),
                // Where to go to change it. The chip is where the question
                // "can I change this?" actually occurs, which makes it a better
                // route to the settings page than remembering a menu group.
                'settings_url' => Config::get('nova-two-factor.settings.editable', false)
                    ? $this->novaPath('dashboards/two-factor-settings')
                    : null,
            ],
        ]);
    }

    /**
     * Mail one user the enrollment reminder.
     *
     * The same notification the bulk Nova action sends, reachable from the row
     * that shows the problem — chasing one person should not mean leaving the
     * page, finding them on a resource index and selecting them again.
     */
    public function remind(Request $request): JsonResponse
    {
        $this->authorizeCompliance($request);

        $model = $this->resolveTarget($request);
        $user = TwoFactorUser::assert($model);

        // Reminding someone who has already complied is how a reminder trains
        // people to ignore it.
        abort_if($user->confirmedTwoFactorMethods()->isNotEmpty(), 422, __('This user already has a method set up.'));

        abort_unless((bool) $model->getAttribute('email'), 422, __('This user has no address to write to.'));

        // Keyed on the recipient, so a second administrator asking a minute
        // later is refused too. The route's limiter caps one session; this caps
        // what lands in one inbox.
        abort_if(
            app(EnrollmentReminders::class)->recentlyReminded($model),
            429,
            __('This user was reminded recently. Try again later.'),
        );

        $enforcement = app(Enforcement::class);
        $mandatory = $enforcement->mode()->blocks();

        Notification::send($model, new EnrollmentReminderNotification(
            // A deadline only where one bites; under `encouraged` the date
            // exists but nothing happens on it.
            $mandatory ? $enforcement->graceEndsAt($user) : null,
            $this->note($request),
            // Captured from the request rather than left to `APP_URL`, which
            // on a panel with its own domain names the customer site.
            PanelUrl::userSecurity(),
            $mandatory,
        ));

        Event::dispatch(new EnrollmentReminderSent($user, context: [
            'by' => (Nova::user($request) ?? $request->user())?->getAuthIdentifier(),
            'note' => $this->note($request),
        ]));

        return response()->json(['message' => __('Reminder sent.')]);
    }

    /**
     * Clear every factor for one user.
     *
     * Destructive, cross-account and irreversible, so it asks for three things:
     * the user's own address typed out, a written reason, and — through the
     * route's middleware — the administrator's password. Typing the address is
     * what stops a reset landing on the row above the intended one.
     */
    public function reset(Request $request): JsonResponse
    {
        $this->authorizeCompliance($request);

        $model = $this->resolveTarget($request);
        $user = TwoFactorUser::assert($model);

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'confirmation' => ['required', 'string'],
            'notify' => ['sometimes', 'boolean'],
        ]);

        $expected = (string) ($model->getAttribute('email') ?? '');

        // Compared case-insensitively but exactly otherwise: an address is not
        // a password, and refusing "GABRIELE@…" for "gabriele@…" would only
        // teach people to paste rather than read.
        abort_unless(
            $expected !== '' && mb_strtolower(trim($validated['confirmation'])) === mb_strtolower($expected),
            422,
            __('That does not match this user\'s email address.'),
        );

        app(ResetTwoFactor::class)(
            $user,
            $validated['reason'],
            Nova::user($request) ?? $request->user(),
        );

        // Opt-in, unticked by default. The mail carries what was removed and
        // how to start again — never the reason, which is written for the
        // audit log and may name an incident or other people.
        if ($request->boolean('notify') && $model->getAttribute('email')) {
            Notification::send($model, new TwoFactorResetNotification(
                PanelUrl::userSecurity(),
                app(Enforcement::class)->mode()->blocks(),
            ));
        }

        return response()->json([
            'message' => $request->boolean('notify')
                ? __('Two-factor authentication reset, and the user was told.')
                : __('Two-factor authentication reset.'),
        ]);
    }

    /**
     * What administrators have done to accounts lately.
     *
     * Scoped to the actions this page offers — reminders, resets, exemptions —
     * rather than the whole trail: a page that lists every sign-in alongside
     * them buries the three rows somebody actually came to check. The full log
     * is one link away.
     *
     * @return array<int, array{event: string, label: string, user: string, by: string|null, detail: string|null, at: string|null}>
     */
    protected function adminEvents(int $limit = 8): array
    {
        $events = [
            AuditEvent::AdminReminderSent,
            AuditEvent::AdminReset,
            AuditEvent::AdminExempted,
        ];

        return TwoFactorAudit::query()
            ->whereIn('event', array_map(static fn (AuditEvent $event): string => $event->value, $events))
            ->latest('created_at')
            ->limit($limit)
            ->get(['event', 'context', 'authenticatable_type', 'authenticatable_id', 'created_at'])
            ->tap(fn ($rows) => $this->primeNames($rows))
            ->map(function ($audit): array {
                $context = $audit->getAttribute('context') ?? [];
                $event = $audit->getAttribute('event');

                return [
                    'event' => $event instanceof AuditEvent ? $event->value : (string) $event,
                    'label' => $event instanceof AuditEvent ? $event->label() : (string) $event,
                    // Who it happened to, and who did it. An admin action with
                    // no actor is the one thing this list must never show.
                    'user' => $this->nameOf(
                        $audit->getAttribute('authenticatable_type'),
                        $audit->getAttribute('authenticatable_id'),
                    ),
                    'by' => isset($context['by']) ? $this->nameOfId($context['by']) : null,
                    'detail' => $context['reason'] ?? $context['note'] ?? null,
                    'at' => $audit->getAttribute('created_at')?->toIso8601String(),
                ];
            })
            ->all();
    }

    protected function nameOfId(mixed $id): ?string
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
     * Owner keys with a trusted device still in force, and with a lockout in
     * the last day.
     *
     * Two small aggregate queries rather than one per row: the walk already
     * touches every audited user, and asking per row is how a page that
     * renders in 200ms starts taking four seconds.
     *
     * @return array<int, string>
     */
    protected function ownersWithTrustedDevices(): array
    {
        if (! Config::get('nova-two-factor.trusted_devices.enabled', true)) {
            return [];
        }

        return TwoFactorTrustedDevice::query()
            ->where('expires_at', '>', now())
            ->get(['authenticatable_type', 'authenticatable_id'])
            ->map(fn ($device): string => $device->getAttribute('authenticatable_type').':'.$device->getAttribute('authenticatable_id'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function recentlyLockedOutOwners(): array
    {
        return TwoFactorAudit::query()
            ->where('event', AuditEvent::ChallengeLockedOut->value)
            ->where('created_at', '>=', now()->subDay())
            ->get(['authenticatable_type', 'authenticatable_id'])
            ->map(fn ($audit): string => $audit->getAttribute('authenticatable_type').':'.$audit->getAttribute('authenticatable_id'))
            ->unique()
            ->values()
            ->all();
    }

    protected function ownerKey(Model $model): string
    {
        return $model->getMorphClass().':'.$model->getKey();
    }

    /**
     * The resilience figures: what is covered, and how well.
     *
     * @param  array<string, int>  $health
     * @param  array<int, int>  $enrolDays
     * @return array<string, mixed>
     */
    protected function health(array $health, array $enrolDays): array
    {
        sort($enrolDays);

        $count = count($enrolDays);

        // Median, not mean: one account enrolled three years after it was
        // created drags an average far enough to describe nobody.
        $median = match (true) {
            $count === 0 => null,
            $count % 2 === 1 => $enrolDays[intdiv($count, 2)],
            default => (int) round(($enrolDays[$count / 2 - 1] + $enrolDays[$count / 2]) / 2),
        };

        return $health + [
            'phishing_resistant_percent' => $health['covered'] === 0
                ? 0
                : (int) round($health['phishing_resistant'] / $health['covered'] * 100),
            'time_to_enrol_days' => $median,
        ];
    }

    /**
     * Trusted devices currently skipping the challenge.
     *
     * The number that quietly undoes enforcement: every one of these is a
     * browser that will not be asked for a second factor until it expires.
     *
     * @return array{active: int, oldest: string|null, enabled: bool}
     */
    protected function devices(): array
    {
        if (! Config::get('nova-two-factor.trusted_devices.enabled', true)) {
            return ['active' => 0, 'oldest' => null, 'enabled' => false];
        }

        $active = TwoFactorTrustedDevice::query()->where('expires_at', '>', now());

        return [
            'active' => (clone $active)->count(),
            'oldest' => optional((clone $active)->min('created_at'))?->toIso8601String()
                ?? (clone $active)->orderBy('created_at')->value('created_at')?->toIso8601String(),
            'enabled' => true,
        ];
    }

    /**
     * People locked out in the last 24 hours.
     *
     * Named rather than counted alone: a count cannot tell an attack from one
     * person fighting their own authenticator clock, and the names usually can.
     *
     * @return array{count: int, users: array<int, string>}
     */
    protected function lockouts(): array
    {
        $rows = TwoFactorAudit::query()
            ->where('event', AuditEvent::ChallengeLockedOut->value)
            ->where('created_at', '>=', now()->subDay())
            ->latest('created_at')
            ->limit(25)
            ->get(['authenticatable_type', 'authenticatable_id']);

        return [
            'count' => $rows->count(),
            'users' => $this->nameAudited($rows),
        ];
    }

    /**
     * Recovery codes spent recently.
     *
     * Legitimate and rare — which is what makes a run of them worth seeing.
     *
     * @return array<int, array{name: string, at: string}>
     */
    protected function recoverySignIns(int $days = 30, int $limit = 5): array
    {
        return TwoFactorAudit::query()
            ->where('event', AuditEvent::RecoveryCodeConsumed->value)
            ->where('created_at', '>=', now()->subDays($days))
            ->latest('created_at')
            ->limit($limit)
            ->get(['authenticatable_type', 'authenticatable_id', 'created_at'])
            ->tap(fn ($rows) => $this->primeNames($rows))
            ->map(fn ($audit): array => [
                'name' => $this->nameOf(
                    $audit->getAttribute('authenticatable_type'),
                    $audit->getAttribute('authenticatable_id'),
                ),
                'at' => $audit->getAttribute('created_at')?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * Phishing-resistant share, month by month.
     *
     * Reconstructed from `confirmed_at` rather than stored: a passkey confirmed
     * in March was protecting that account in April too, so each month counts
     * every method confirmed up to its end. This is the only series on the page
     * that says whether the programme is working rather than merely growing.
     *
     * @return array<int, array{month: string, percent: int}>
     */
    protected function resistantTrend(int $months = 12): array
    {
        $methods = TwoFactorMethod::query()
            ->whereNotNull('confirmed_at')
            ->get(['type', 'confirmed_at', 'authenticatable_id', 'authenticatable_type']);

        $series = [];

        for ($offset = $months - 1; $offset >= 0; $offset--) {
            $end = now()->subMonths($offset)->endOfMonth();
            $upTo = $methods->filter(
                static fn ($method): bool => $method->confirmed_at !== null && $method->confirmed_at->lte($end),
            );

            $owner = static fn ($method): string => $method->getAttribute('authenticatable_type').':'.$method->getAttribute('authenticatable_id');

            $covered = $upTo->map($owner)->unique();
            $resistant = $upTo->filter(static fn ($method): bool => $method->type === MethodType::WebAuthn)->map($owner)->unique();

            $series[] = [
                'month' => $end->format('Y-m'),
                'percent' => $covered->isEmpty() ? 0 : (int) round($resistant->count() / $covered->count() * 100),
            ];
        }

        return $series;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, TwoFactorAudit>  $rows
     * @return array<int, string>
     */
    protected function nameAudited($rows): array
    {
        return $rows
            ->tap(fn ($rows) => $this->primeNames($rows))
            ->map(fn ($audit): string => $this->nameOf(
                $audit->getAttribute('authenticatable_type'),
                $audit->getAttribute('authenticatable_id'),
            ))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Resolve every owner in a set of rows with one query per model.
     *
     * The lists on this page are short, but they are read row by row — and a
     * lookup per row is a query per row. Priming them together keeps the page
     * at one query per morph type however long the lists grow.
     *
     * @param  iterable<int, Model>  $rows
     */
    protected function primeNames(iterable $rows): void
    {
        $wanted = [];

        foreach ($rows as $row) {
            $type = $row->getAttribute('authenticatable_type');
            $id = $row->getAttribute('authenticatable_id');

            if ($type === null || $id === null || isset($this->names[$type.':'.$id])) {
                continue;
            }

            $wanted[$type][] = $id;
        }

        foreach ($wanted as $type => $ids) {
            $class = $this->modelFor($type);

            if ($class === null) {
                continue;
            }

            foreach ($class::query()->findMany(array_unique($ids)) as $model) {
                $this->names[$type.':'.$model->getKey()] = $this->displayName($model);
            }
        }
    }

    protected function nameOf(?string $type, mixed $id): string
    {
        if ($type === null || $id === null) {
            return __('Unknown');
        }

        if (isset($this->names[$type.':'.$id])) {
            return $this->names[$type.':'.$id];
        }

        $class = $this->modelFor($type);

        if ($class === null) {
            return __('Unknown');
        }

        $model = $class::query()->find($id);

        return $this->names[$type.':'.$id] = $this->displayName($model);
    }

    /**
     * The class behind a stored morph type.
     *
     * Through the morph map first: an application that calls
     * `enforceMorphMap()` stores the alias, and `class_exists('user')` is
     * false — which rendered every name on this dashboard as "Unknown".
     *
     * @return class-string<Model>|null
     */
    protected function modelFor(string $type): ?string
    {
        $class = Relation::getMorphedModel($type) ?? $type;

        /** @var class-string<Model>|null */
        return class_exists($class) && is_subclass_of($class, Model::class) ? $class : null;
    }

    protected function displayName(?Model $model): string
    {
        return (string) ($model?->getAttribute('name') ?? $model?->getAttribute('email') ?? __('Unknown'));
    }

    /**
     * Which groups of figure this mode can honestly show.
     *
     * Three of them are not merely quiet in the wrong mode, they are wrong.
     * "In grace" and "overdue" are categories with no referent under
     * `optional` — nothing is due, so nothing can be overdue — and a coverage
     * strip where every row reads "not required" is one grey band pretending to
     * be information. Trusted devices are the same: nothing to undo where
     * nothing is enforced.
     *
     * @return array<string, bool>
     */
    protected function sections(Enforcement $enforcement): array
    {
        $mode = $enforcement->mode();

        return [
            // Coverage only exists where there is a policy.
            'coverage' => $mode !== EnforcementMode::Optional,
            // Deadlines only bite where something blocks.
            'grace' => $mode === EnforcementMode::Required,
            'time_to_enrol' => $mode !== EnforcementMode::Optional,
            'trusted_devices' => $mode !== EnforcementMode::Optional,
            // Resilience and security signals are about people who already
            // enrolled, and about attacks. Neither depends on the policy.
            'resilience' => true,
            'signals' => true,
        ];
    }

    /**
     * The one figure that only makes sense in this mode.
     *
     * Each mode has a mechanism, and each mechanism has a number that says
     * whether it is working: encouragement is measured by how often it is waved
     * away, a requirement by how often it actually stops someone, and an
     * optional policy by whether anyone is choosing it unprompted.
     *
     * @return array{key: string, label: string, value: int, caption: string}|null
     */
    protected function spotlight(Enforcement $enforcement): ?array
    {
        return match ($enforcement->mode()) {
            EnforcementMode::Required => [
                'key' => 'blocked',
                'label' => __('Blocked at the door'),
                'value' => $this->auditCount(AuditEvent::EnforcementBlocked, 7),
                'caption' => __('in the last 7 days'),
            ],
            // Reminders *sent*, not reminders dismissed. This is the page an
            // administrator sends them from, so it is the number that has to
            // move when they do — dismissals are a user behaviour, and they
            // are in the activity log.
            EnforcementMode::Encouraged => [
                'key' => 'reminders',
                'label' => __('Reminders sent'),
                'value' => $this->auditCount(AuditEvent::AdminReminderSent, 30),
                'caption' => __('in the last 30 days'),
            ],
            EnforcementMode::Optional => [
                'key' => 'new',
                'label' => __('New enrolments'),
                'value' => $this->auditCount(AuditEvent::MethodConfirmed, 30),
                'caption' => __('in the last 30 days'),
            ],
        };
    }

    protected function auditCount(AuditEvent $event, int $days): int
    {
        return TwoFactorAudit::query()
            ->where('event', $event->value)
            ->where('created_at', '>=', now()->subDays($days))
            ->count();
    }

    /**
     * The method mix, ordered strongest factor first.
     *
     * Order is the point: a passkey and an email code are not two equivalent
     * options, and a chart that sorts by count invites reading the tallest bar
     * as the best outcome.
     *
     * @param  array<string, int>  $mix
     * @return array<int, array{type: string, label: string, count: int}>
     */
    protected function mix(array $mix): array
    {
        $ordered = [];

        foreach ([MethodType::WebAuthn, MethodType::Totp, MethodType::Email] as $type) {
            if (($mix[$type->value] ?? 0) > 0) {
                $ordered[] = [
                    'type' => $type->value,
                    'label' => $type->label(),
                    'count' => $mix[$type->value],
                ];
            }
        }

        return $ordered;
    }

    /**
     * Failed challenges per day, oldest first.
     *
     * A flat line near zero with a sudden spike is what credential stuffing
     * looks like from the inside, so the shape matters more than the total —
     * and every day appears, including the empty ones, because a series that
     * omits its zeroes draws a flat line through a gap and hides the spike.
     *
     * @return array<int, array{date: string, count: int}>
     */
    protected function failures(int $days = 30): array
    {
        $since = now()->subDays($days - 1)->startOfDay();

        $counts = TwoFactorAudit::query()
            ->where('event', AuditEvent::ChallengeFailed->value)
            ->where('created_at', '>=', $since)
            ->pluck('created_at')
            ->countBy(static fn ($timestamp): string => $timestamp->toDateString());

        $series = [];

        for ($day = 0; $day < $days; $day++) {
            $date = $since->copy()->addDays($day)->toDateString();

            $series[] = ['date' => $date, 'count' => (int) ($counts[$date] ?? 0)];
        }

        return $series;
    }

    protected function note(Request $request): ?string
    {
        $note = trim((string) $request->input('note', ''));

        return $note === '' ? null : mb_substr($note, 0, 500);
    }

    /**
     * The row's model, resolved only from the audited set.
     *
     * The class arrives from the browser, so it is matched against the
     * configured populations rather than trusted: `new $class` on an
     * attacker-chosen string is how an admin endpoint becomes an arbitrary
     * object instantiation.
     */
    protected function resolveTarget(Request $request): Model
    {
        $validated = $request->validate([
            'model' => ['required', 'string'],
            'id' => ['required'],
        ]);

        abort_unless(
            array_key_exists($validated['model'], AuditedModels::all()),
            422,
            __('That user is not in scope.'),
        );

        /** @var class-string<Model> $class */
        $class = $validated['model'];

        return $class::query()->findOrFail($validated['id']);
    }

    /**
     * Eager-load the confirmed methods, and apply the search.
     *
     * Searching in SQL rather than filtering the walk: a name typed into the box
     * should narrow the query, not make the server read the whole table and
     * throw most of it away.
     */
    protected function narrow(Builder $query, string $search): Builder
    {
        $query
            ->with(['twoFactorMethods' => static fn ($methods) => $methods->whereNotNull('confirmed_at')])
            // Counted, not loaded: the codes themselves are hashes nobody on
            // this page may see, and only how many are left is a figure.
            ->withCount(['twoFactorRecoveryCodes as unused_recovery_codes' => static fn ($codes) => $codes->whereNull('used_at')]);

        if ($search === '') {
            return $query;
        }

        return $query->where(static function (Builder $scoped) use ($search): void {
            foreach (['name', 'email'] as $column) {
                $scoped->orWhere($column, 'like', '%'.$search.'%');
            }
        });
    }

    protected function initials(Model $model): string
    {
        $source = (string) ($model->getAttribute('name') ?: $model->getAttribute('email') ?: '?');

        $words = preg_split('/[\s@._-]+/', trim($source), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];

        return mb_strtoupper(mb_substr($words[0], 0, 1).(count($words) > 1 ? mb_substr($words[1], 0, 1) : ''));
    }

    /**
     * A link to the row's own Nova resource, when the model has one.
     *
     * "Who is this?" is the next question after "who is overdue", and answering
     * it inside the compliance page would mean rebuilding the user detail screen
     * Nova already has.
     */
    protected function resourceUrl(Model $model): ?string
    {
        $resource = Nova::resourceForModel($model);

        if ($resource === null) {
            return null;
        }

        return $this->novaPath('resources/'.$resource::uriKey().'/'.$model->getKey());
    }

    protected function authorizeCompliance(Request $request): void
    {
        $this->novaUserOrFail();

        $gate = Config::get('nova-two-factor.nova.admin_gate');

        if (! is_string($gate) || $gate === '') {
            return;
        }

        abort_unless(Gate::forUser($this->novaUser())->allows($gate), 403);
    }
}
