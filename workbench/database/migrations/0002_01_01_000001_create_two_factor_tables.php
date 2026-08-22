<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

/**
 * The package ships its migration as a publishable `.stub`, which the migrator
 * deliberately ignores. Including it here means the workbench exercises the
 * exact file consumers publish, rather than a second copy that can drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->package()->up();
    }

    public function down(): void
    {
        $this->package()->down();
    }

    private function package(): Migration
    {
        return require __DIR__.'/../../../database/migrations/create_two_factor_tables.php.stub';
    }
};
