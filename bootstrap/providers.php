<?php

use App\Modules\Attendance\AttendanceServiceProvider;
use App\Modules\Central\CentralServiceProvider;
use App\Modules\Core\CoreServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Modules\Leave\LeaveServiceProvider;
use App\Modules\Organization\OrganizationServiceProvider;
use App\Modules\Overtime\OvertimeServiceProvider;
use App\Modules\Scheduling\SchedulingServiceProvider;
use App\Modules\Timetable\TimetableServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    HorizonServiceProvider::class,
    TenancyServiceProvider::class,

    // Modules (Core first: others register into its catalogs and registries).
    CoreServiceProvider::class,
    CentralServiceProvider::class,
    IdentityServiceProvider::class,
    OrganizationServiceProvider::class,
    TimetableServiceProvider::class,
    SchedulingServiceProvider::class,
    AttendanceServiceProvider::class,
    LeaveServiceProvider::class,
    OvertimeServiceProvider::class,
];
