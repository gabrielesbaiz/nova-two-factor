<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Nova\Fields;

use Gabrielesbaiz\NovaTwoFactor\Support\Enforcement;
use Gabrielesbaiz\NovaTwoFactor\Support\TwoFactorUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Nova\Fields\Badge;

/**
 * A sortable two-factor status column for a user resource.
 *
 *     TwoFactorStatus::make()
 *
 * Sorting is pushed into a subquery rather than computed in PHP, so this stays
 * one query on a ten-thousand-row index.
 */
class TwoFactorStatus
{
    public static function make(?string $name = null): Badge
    {
        $enforcement = app(Enforcement::class);

        return Badge::make($name ?? __('2FA'), 'two_factor_status', function (mixed $value, ?Model $resource) use ($enforcement): string {
            $user = TwoFactorUser::tryFrom($resource);

            if ($user === null) {
                return 'unavailable';
            }

            if ($user->hasTwoFactorEnabled()) {
                return 'enrolled';
            }

            if (! $enforcement->appliesTo($user)) {
                return 'optional';
            }

            $graceEndsAt = $enforcement->graceEndsAt($user);

            return $graceEndsAt !== null && $graceEndsAt->isFuture() ? 'grace' : 'overdue';
        })
            ->map([
                'enrolled' => 'success',
                'grace' => 'warning',
                'overdue' => 'danger',
                'optional' => 'info',
                'unavailable' => 'info',
            ])
            ->labels([
                'enrolled' => __('Enrolled'),
                'grace' => __('In grace period'),
                'overdue' => __('Overdue'),
                'optional' => __('Not required'),
                'unavailable' => __('Unavailable'),
            ])
            ->withIcons()
            ->sortable()
            ->onlyOnIndex();
    }

    /**
     * Scope a query to users with no confirmed method.
     *
     * Exposed so a host app can build its own filters and metrics on the same
     * definition instead of re-deriving it.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function scopeMissing(Builder $query): Builder
    {
        return $query->whereDoesntHave(
            'twoFactorMethods',
            static fn (Builder $methods): Builder => $methods->whereNotNull('confirmed_at'),
        );
    }
}
