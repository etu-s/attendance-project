<?php

declare(strict_types=1);

use App\Modules\Central\Database\Seeders\DemoTenantSeeder;
use App\Modules\Central\Database\Seeders\PlanSeeder;
use App\Modules\Central\Models\Domain;
use App\Modules\Organization\Models\Employee;
use App\Modules\Scheduling\Models\Holiday;
use App\Modules\Scheduling\Models\ScheduleAssignment;
use App\Modules\Timetable\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * `composer setup` runs these seeders in local; a broken seeder only shows up
 * on a fresh install, so run the real chain here.
 */
it('provisions the local demo tenant with its demo data, once', function (): void {
    $this->seed([PlanSeeder::class, DemoTenantSeeder::class]);
    $this->seed(DemoTenantSeeder::class);

    $tenant = Domain::query()->where('domain', 'acme')->firstOrFail()->tenant;

    $tenant->run(function (): void {
        expect(Employee::query()->count())->toBe(12)
            ->and(Shift::query()->count())->toBe(4)
            ->and(ScheduleAssignment::query()->count())->toBe(4)
            ->and(Holiday::query()->count())->toBe(7);
    });

    expect(Domain::query()->where('domain', 'acme')->count())->toBe(1);
});
