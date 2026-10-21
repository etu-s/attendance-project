<?php

declare(strict_types=1);

namespace App\Modules\Scheduling;

use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Permissions\PermissionCatalog;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Scheduling\Models\Holiday;
use App\Modules\Scheduling\Models\ScheduleAssignment;
use App\Modules\Scheduling\Models\TemporarySchedule;
use App\Modules\Scheduling\Services\HolidayService;
use App\Modules\Scheduling\Services\ScheduleAssignmentService;
use App\Modules\Scheduling\Services\ScheduleResolver;
use App\Modules\Scheduling\Services\ScheduleTargets;
use App\Modules\Scheduling\Services\TemporaryScheduleService;
use App\Modules\Timetable\Services\UsageRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Scheduling: which shift applies to whom on which date (organization,
 * department, group and employee levels, temporary overrides, holidays).
 * Module 5 uses ScheduleResolver and listens to ScheduleChanged.
 */
final class SchedulingServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        foreach ([
            ScheduleTargets::class, ScheduleAssignmentService::class, TemporaryScheduleService::class,
            HolidayService::class, ScheduleResolver::class,
        ] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        $this->bootModule();

        Relation::enforceMorphMap([
            'schedule_assignment' => ScheduleAssignment::class,
            'temporary_schedule' => TemporarySchedule::class,
            'holiday' => Holiday::class,
        ]);

        // Shifts used by a started schedule become read-only (Timetable module).
        $this->app->make(UsageRegistry::class)->whenShiftInUse(
            fn (int $shiftId): bool => $this->app->make(ScheduleAssignmentService::class)->shiftInUse($shiftId),
        );

        $catalog = $this->app->make(PermissionCatalog::class);
        $catalog->register('schedules.view', [RoleName::HrManager, RoleName::DepartmentManager]);
        $catalog->register('schedules.manage', [RoleName::HrManager]);
        $catalog->register('holidays.view', [RoleName::HrManager, RoleName::DepartmentManager, RoleName::Employee]);
        $catalog->register('holidays.manage', [RoleName::HrManager]);
    }
}
