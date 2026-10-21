<?php

declare(strict_types=1);

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\PunchSource;
use App\Modules\Central\Database\Seeders\DemoTenantSeeder;
use App\Modules\Central\Database\Seeders\PlanSeeder;
use App\Modules\Central\Models\Domain;
use App\Modules\Organization\Models\Employee;
use App\Modules\Scheduling\Models\Holiday;
use App\Modules\Scheduling\Models\ScheduleAssignment;
use App\Modules\Timetable\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * `composer setup` runs these seeders in local; a broken seeder only shows up
 * on a fresh install, so run the real chain here.
 */
it('provisions the local demo tenant with its demo data, once', function (): void {
    // The punch simulation covers the week up to "today".
    $this->travelTo(CarbonImmutable::parse('2026-03-06 12:00:00', 'UTC'));
    $this->seed([PlanSeeder::class, DemoTenantSeeder::class]);
    $this->seed(DemoTenantSeeder::class);

    $tenant = Domain::query()->where('domain', 'acme')->firstOrFail()->tenant;

    $tenant->run(function (): void {
        expect(Employee::query()->count())->toBe(12)
            ->and(Shift::query()->count())->toBe(4)
            ->and(ScheduleAssignment::query()->count())->toBe(4)
            ->and(Holiday::query()->count())->toBe(7)
            ->and(PunchSource::query()->orderBy('name')->pluck('name')->all())->toBe(['Main entrance', 'Simulator'])
            ->and(AttendanceDay::query()->count())->toBeGreaterThan(50);
    });

    expect(Domain::query()->where('domain', 'acme')->count())->toBe(1);
});
