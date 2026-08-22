<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Workbench\App\Models\Admin;
use Workbench\App\Models\User;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Gabriele Sbaiz',
            'email' => 'gabriele.sbaiz@noviasnet.it',
            'password' => 'password',
        ]);

        Admin::factory()->create([
            'name' => 'Workbench Admin',
            'email' => 'admin@example.test',
            'password' => 'password',
        ]);
    }
}
