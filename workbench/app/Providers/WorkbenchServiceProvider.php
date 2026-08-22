<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Models\Admin;
use Workbench\App\Models\User;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Factory::guessFactoryNamesUsing(
            static fn (string $model): string => 'Workbench\\Database\\Factories\\'.class_basename($model).'Factory',
        );
    }

    public function boot(): void
    {
        // An explicit morph map, because the package stores the morph alias in
        // four tables. Without one, renaming or namespacing a model silently
        // orphans every enrolled factor.
        \Illuminate\Database\Eloquent\Relations\Relation::enforceMorphMap([
            'user' => User::class,
            'admin' => Admin::class,
        ]);
    }
}
