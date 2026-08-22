<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Nova\Metrics;

use DateTimeInterface;
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

    /** @var class-string<Model> */
    protected string $model;

    /**
     * @param  class-string<Model>  $model
     */
    public function __construct(string $model)
    {
        parent::__construct();

        $this->model = $model;
        $this->name = __('Two-factor adoption');
    }

    public function calculate(NovaRequest $request): PartitionResult
    {
        $enforcement = app(Enforcement::class);

        $counts = ['enrolled' => 0, 'grace' => 0, 'overdue' => 0, 'optional' => 0];

        // Chunked rather than loaded whole: this runs against the user table,
        // which is the one table guaranteed to be large.
        $this->model::query()
            ->with(['twoFactorMethods' => static fn ($query) => $query->whereNotNull('confirmed_at')])
            ->chunkById(500, function ($users) use (&$counts, $enforcement): void {
                foreach ($users as $model) {
                    $user = TwoFactorUser::tryFrom($model);

                    if ($user === null) {
                        continue;
                    }

                    if ($user->confirmedTwoFactorMethods()->isNotEmpty()) {
                        $counts['enrolled']++;

                        continue;
                    }

                    if (! $enforcement->appliesTo($user)) {
                        $counts['optional']++;

                        continue;
                    }

                    $graceEndsAt = $enforcement->graceEndsAt($user);

                    if ($graceEndsAt !== null && $graceEndsAt->isFuture()) {
                        $counts['grace']++;
                    } else {
                        $counts['overdue']++;
                    }
                }
            });

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
