<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Database\Seeders;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Group;
use App\Modules\Scheduling\Enums\ScheduleTarget;
use App\Modules\Scheduling\Models\ScheduleAssignment;
use App\Modules\Scheduling\Services\HolidayService;
use App\Modules\Scheduling\Services\ScheduleAssignmentService;
use App\Modules\Timetable\Models\Shift;
use Illuminate\Database\Seeder;

/**
 * Local demo schedules and holidays (run inside tenant context, after the
 * Organization and Timetable demo seeders). Skips tenants with schedules.
 */
final class DemoSchedulingSeeder extends Seeder
{
    public function run(ScheduleAssignmentService $assignments, HolidayService $holidays): void
    {
        if (ScheduleAssignment::query()->exists()) {
            return;
        }

        $shift = fn (string $code) => (int) Shift::query()->where('code', $code)->value('id');
        $from = LocalDate::parse('2025-01-01');

        $assignments->assign(ScheduleTarget::Organization, null, $shift('OFFICE'), $from);

        if ($warehouse = Department::query()->where('code', 'WH')->value('id')) {
            $assignments->assign(ScheduleTarget::Department, (int) $warehouse, $shift('ROTA44'), $from, null, LocalDate::parse('2025-01-06'));
        }
        if ($hr = Department::query()->where('code', 'HR')->value('id')) {
            $assignments->assign(ScheduleTarget::Department, (int) $hr, $shift('FLEXWEEK'), $from);
        }
        if ($night = Group::query()->where('code', 'NIGHT')->value('id')) {
            $assignments->assign(ScheduleTarget::Group, (int) $night, $shift('NIGHTS'), $from, null, LocalDate::parse('2025-01-06'));
        }

        foreach ([
            ['2025-02-05', 'Kashmir Solidarity Day', true],
            ['2025-03-23', 'Pakistan Day', true],
            ['2025-05-01', 'Labour Day', true],
            ['2025-08-14', 'Independence Day', true],
            ['2025-12-25', 'Quaid-e-Azam Day', true],
            ['2026-03-20', 'Eid al-Fitr', false],
            ['2026-05-27', 'Eid al-Adha', false],
        ] as [$date, $name, $recurs]) {
            $holidays->create(['date' => $date, 'name' => $name, 'recurs_yearly' => $recurs]);
        }
    }
}
