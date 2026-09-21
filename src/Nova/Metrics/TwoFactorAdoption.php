<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Nova\Metrics;

use DateTimeInterface;
use Gabrielesbaiz\NovaTwoFactor\Support\AuditedModels;
use Gabrielesbaiz\NovaTwoFactor\Support\Enforcement;
use Gabrielesbaiz\NovaTwoFactor\Support\TwoFactorUser;
use Illuminate\Database\Eloquent\Model;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Metrics\Partition;
use Laravel\Nova\Metrics\PartitionResult;

/**
 * Enrolled / in grace / overdue, across the user model.
 *
 * The weak-method case is deliberately visible in the sibling
 * {@see TwoFactorMethodMix} metric rather than folded in here: a user whose only
 * factor is an email code counts as enrolled, and a compliance view that hides
 * that is telling you something comfortable rather than something true.
 */
class TwoFactorAdoption extends Partition
{
    public $name;

    /**
     * An explicit model still works — `new TwoFactorAdoption(User::class)` is
     * what the README has always documented, and a host that wires the card
     * onto one resource means that one model. Omit it and the metric measures
     * every audited population instead, which is what the compliance dashboard
     * wants.
     *
     * @var class-string<Model>|null
     */
    protected ?string $model;

    /**
     * @param  class-string<Model>|null  $model
     */
    public function __construct(?string $model = null)
    {
        parent::__construct();

        $this->model = $model;
        $this->name = __('Two-factor adoption');
    }

    public function calculate(NovaRequest $request): PartitionResult
    {
        $enforcement = app(Enforcement::class);

        $counts = ['enrolled' => 0, 'grace' => 0, 'overdue' => 0, 'optional' => 0];

        $tally = function ($model) use (&$counts, $enforcement): void {
            $user = TwoFactorUser::tryFrom($model);

            if ($user === null) {
                return;
            }

            if ($user->confirmedTwoFactorMethods()->isNotEmpty()) {
                $counts['enrolled']++;

                return;
            }

            if (! $enforcement->appliesTo($user)) {
                $counts['optional']++;

                return;
            }

            $graceEndsAt = $enforcement->graceEndsAt($user);

            if ($graceEndsAt !== null && $graceEndsAt->isFuture()) {
                $counts['grace']++;
            } else {
                $counts['overdue']++;
            }
        };

        // Eager-loaded and chunked rather than loaded whole: this runs against
        // the user table, which is the one table guaranteed to be large, and
        // every row needs its confirmed methods.
        $withMethods = static fn ($query) => $query->with([
            'twoFactorMethods' => static fn ($methods) => $methods->whereNotNull('confirmed_at'),
        ]);

        if ($this->model !== null) {
            $withMethods($this->model::query())->chunkById(500, static function ($users) use ($tally): void {
                foreach ($users as $user) {
                    $tally($user);
                }
            });
        } else {
            AuditedModels::each($tally, $withMethods);
        }

        return $this->result(array_filter($counts))
            ->label(fn (string $key): string => match ($key) {
                'enrolled' => __('Enrolled'),
                'grace' => __('In grace period'),
                'overdue' => __('Overdue'),
                default => __('Not required'),
            })
            ->colors([
                'enrolled' => '#22c55e',
                'grace' => '#f59e0b',
                'overdue' => '#ef4444',
                'optional' => '#94a3b8',
            ]);
    }

    public function cacheFor(): DateTimeInterface
    {
        return now()->addMinutes(5);
    }

    public function uriKey(): string
    {
        return 'two-factor-adoption';
    }
}
