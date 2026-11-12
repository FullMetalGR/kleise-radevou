<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * What php artisan db:seed runs in development: the two demo tenants.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(DemoTenantsSeeder::class);
    }
}
