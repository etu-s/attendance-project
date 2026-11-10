<?php

declare(strict_types=1);

namespace App\Modules\Reports\Tests\Support;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Leave\Tests\Support\LeaveTesting;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Reports\Services\DailyStatsBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;

/**
 * LeaveTesting's workspace (HR, a manager of E-1's department, E-1's own
 * login) plus X-1 in a second department the manager does not manage, and a
 * known week of calculated attendance (Mon 2 – Sat 7 March 2026):
 *
 *        Mon      Tue       Wed          Thu       Fri                  Sat
 *  E-1   present  late 20   absent       on leave  present              rest
 *  X-1   present  present   early 30     absent    late 10 + early 15   –
 *
 * Everyone: 7 attended of 9 working days (77.8 %), 2 late days (71.4 %).
 * The manager (E-1 only): 3 of 4 (75.0 %), 1 late day (66.7 %).
 *
 * @property Department $otherDepartment
 * @property Employee $other
 */
trait ReportsTesting
{
    use LeaveTesting;

    protected function reportsWorkspace(string $subdomain = 'acme'): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-03-20 12:00:00', 'UTC'));
        $this->leaveWorkspace($subdomain);

        [$this->otherDepartment, $this->other] = $this->tenant->run(function (): array {
            $department = Department::factory()->create(['name' => 'Warehouse']);
            $other = Employee::factory()->inDepartment($department)->create([
                'employee_code' => 'X-1', 'first_name' => 'Xena', 'last_name' => 'Young', 'hire_date' => '2026-01-01',
            ]);
            $this->employee->update(['first_name' => 'Ada', 'last_name' => 'Abbott']);

            $e = ['employee_id' => $this->employee->id, 'department_id' => $this->department->id];
            $x = ['employee_id' => $other->id, 'department_id' => $department->id];

            AttendanceDay::factory()->create($e + ['work_date' => '2026-03-02']);
            AttendanceDay::factory()->late(20)->create($e + ['work_date' => '2026-03-03']);
            AttendanceDay::factory()->absent()->create($e + ['work_date' => '2026-03-04']);
            AttendanceDay::factory()->off(AttendanceStatus::OnLeave)->create($e + ['work_date' => '2026-03-05']);
            AttendanceDay::factory()->create($e + ['work_date' => '2026-03-06']);
            AttendanceDay::factory()->off(AttendanceStatus::Rest)->create($e + ['work_date' => '2026-03-07']);

            AttendanceDay::factory()->create($x + ['work_date' => '2026-03-02']);
            AttendanceDay::factory()->create($x + ['work_date' => '2026-03-03']);
            AttendanceDay::factory()->earlyLeave(30)->create($x + ['work_date' => '2026-03-04']);
            AttendanceDay::factory()->absent()->create($x + ['work_date' => '2026-03-05']);
            AttendanceDay::factory()->create($x + [
                'work_date' => '2026-03-06', 'status' => AttendanceStatus::LateEarly,
                'late_minutes' => 10, 'early_leave_minutes' => 15, 'worked_minutes' => 455,
            ]);

            app(DailyStatsBuilder::class)->rebuild(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

            return [$department, $other];
        });
    }

    /**
     * Export files live in the tenant's own storage directory; remove it.
     */
    protected function removeTenantStorage(): void
    {
        if (isset($this->tenant)) {
            $this->tenant->run(fn () => File::deleteDirectory(storage_path()));
        }
    }
}
