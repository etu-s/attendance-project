<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Central\Database\Seeders\DemoTenantSeeder;
use App\Modules\Central\Database\Seeders\PlanSeeder;
use App\Modules\Central\Database\Seeders\SuperAdminSeeder;
use Illuminate\Database\Seeder;

/**
 * Central database seeder (`php artisan migrate --seed`).
 * The demo tenant is created only in local development.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlanSeeder::class);

        if (app()->environment('local')) {
            $this->call([SuperAdminSeeder::class, DemoTenantSeeder::class]);
        }
    }
}
