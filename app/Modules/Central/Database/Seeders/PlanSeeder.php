<?php

declare(strict_types=1);

namespace App\Modules\Central\Database\Seeders;

use App\Modules\Central\Models\Plan;
use Illuminate\Database\Seeder;

final class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['slug' => 'starter', 'name' => 'Starter', 'max_employees' => 50, 'features' => ['attendance', 'leave']],
            ['slug' => 'business', 'name' => 'Business', 'max_employees' => 500, 'features' => ['attendance', 'leave', 'overtime', 'reports']],
            ['slug' => 'enterprise', 'name' => 'Enterprise', 'max_employees' => null, 'features' => ['attendance', 'leave', 'overtime', 'reports', 'api']],
        ];

        foreach ($plans as $plan) {
            Plan::query()->updateOrCreate(['slug' => $plan['slug']], $plan + ['is_active' => true]);
        }
    }
}
