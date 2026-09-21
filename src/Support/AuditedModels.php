<?php

declare(strict_types=1);

namespace Gabrielesbaiz\NovaTwoFactor\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

/**
 * Which populations compliance is measured against.
 *
 * A Nova panel is rarely "every row in `users`". On a multi-tenant install only
 * one model can reach the panel at all, and counting the rest turns an adoption
 * figure into a number that is confidently wrong — 87% of a population that was
 * never in scope. Worse, it reads as reassuring.
 *
 * Two ways in, because they solve different problems:
 *
 *   - `nova.compliance.models` in config, class names only. Survives
 *     `config:cache`, which a closure would not.
 *   - `NovaTwoFactor::make()->audit(Admin::class, fn ($query) => $query->where('active', true))`
 *     in `NovaServiceProvider`, where a closure is free to live.
 *
 * Neither set means "count the model behind Nova's guard", which is right for
 * the ordinary single-model app and is what every figure did before scoping
 * existed.
 */
final class AuditedModels
{
    /** @var array<class-string<Model>, Closure(Builder): Builder|null> */
    protected static array $registered = [];

    /**
     * @param  class-string<Model>  $model
     * @param  (Closure(Builder): Builder)|null  $scope
     */
    public static function register(string $model, ?Closure $scope = null): void
    {
        self::$registered[$model] = $scope;
    }

    public static function flush(): void
    {
        self::$registered = [];
    }

    /**
     * Every audited model, as `class-string => scope|null`.
     *
     * Runtime registrations win over configured ones for the same class: the
     * config states the population, a provider refines it.
     *
     * @return array<class-string<Model>, Closure(Builder): Builder|null>
     */
    public static function all(): array
    {
        $models = [];

        foreach ((array) Config::get('nova-two-factor.nova.compliance.models', []) as $model) {
            if (is_string($model) && class_exists($model) && is_subclass_of($model, Model::class)) {
                $models[$model] = null;
            }
        }

        $models = array_merge($models, self::$registered);

        if ($models === []) {
            $guarded = UserModel::resolve();

            if ($guarded !== null) {
                $models[$guarded] = null;
            }
        }

        return $models;
    }

    /**
     * A query per audited model, already narrowed to the population in scope.
     *
     * @return Collection<int, Builder>
     */
    public static function queries(): Collection
    {
        return collect(self::all())->map(static function (?Closure $scope, string $model): Builder {
            /** @var Builder $query */
            $query = $model::query();

            return $scope === null ? $query : $scope($query);
        })->values();
    }

    /**
     * Run a callback against every audited row, in chunks.
     *
     * Chunked rather than loaded whole because the audited population is the
     * one table guaranteed to be large, and every caller here walks all of it.
     *
     * @param  Closure(Model): void  $callback
     */
    public static function each(Closure $callback, ?Closure $narrow = null): void
    {
        foreach (self::queries() as $query) {
            ($narrow === null ? $query : $narrow($query))
                ->chunkById(500, static function ($rows) use ($callback): void {
                    foreach ($rows as $row) {
                        $callback($row);
                    }
                });
        }
    }
}
