<?php

declare(strict_types=1);

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Organization\Models\Employee;
use App\Modules\Overtime\Enums\OvertimeStatus;
use App\Modules\Overtime\Models\OvertimeRecord;
use App\Modules\Reports\Tests\Support\ReportsTesting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class, ReportsTesting::class);

beforeEach(function (): void {
    $this->reportsWorkspace();
    $this->march = 'from=2026-03-01&to=2026-03-31';
});

describe('attendance summary', function (): void {
    it('totals each employee, sorted by name by default', function (): void {
        $this->actingAsTenantUser($this->hr)
            ->getJson($this->tenantUrl($this->tenant, "reports/attendance-summary?{$this->march}"))
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.employee.employee_code', 'E-1')
            ->assertJsonPath('data.0.working_days', 4)
            ->assertJsonPath('data.0.attended_days', 3)
            ->assertJsonPath('data.0.absent_days', 1)
            ->assertJsonPath('data.0.leave_days', 1)
            ->assertJsonPath('data.0.rest_days', 1)
            ->assertJsonPath('data.0.late_minutes', 20)
            ->assertJsonPath('data.0.attendance_rate', 75)
            ->assertJsonPath('data.1.employee.full_name', 'Xena Young')
            ->assertJsonPath('data.1.early_leave_minutes', 45);
    });

    it('sorts, searches and filters, within the viewer\'s scope', function (): void {
        $this->actingAsTenantUser($this->hr);
        $url = fn (string $q) => $this->tenantUrl($this->tenant, "reports/attendance-summary?{$this->march}&{$q}");

        $this->getJson($url('sort=-name'))->assertJsonPath('data.0.employee.employee_code', 'X-1');
        $this->getJson($url('sort=-early_leave_minutes'))->assertJsonPath('data.0.employee.employee_code', 'X-1');
        $this->getJson($url('sort=-late_minutes'))->assertJsonPath('data.0.employee.employee_code', 'E-1');
        $this->getJson($url('search=young'))->assertJsonPath('meta.total', 1);
        $this->getJson($url('department_id='.$this->otherDepartment->id))->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.employee.employee_code', 'X-1');
        $this->getJson($url('sort=salary'))->assertJsonValidationErrors('sort');

        $this->actingAsTenantUser($this->manager)->getJson($url('per_page=10'))
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.employee.employee_code', 'E-1');
        $this->actingAsTenantUser($this->worker)->getJson($url(''))->assertForbidden();
    });

    it('runs a fixed number of queries however many employees there are', function (): void {
        $this->actingAsTenantUser($this->hr);
        $count = function (): int {
            $queries = 0;
            DB::listen(function () use (&$queries): void {
                $queries++;
            });
            $this->getJson($this->tenantUrl($this->tenant, "reports/attendance-summary?{$this->march}&per_page=50"))->assertOk();

            return $queries;
        };

        $before = $count();
        $this->tenant->run(function (): void {
            foreach (Employee::factory()->count(10)->inDepartment($this->department)->create(['hire_date' => '2026-01-01']) as $employee) {
                AttendanceDay::factory()->create(['employee_id' => $employee->id, 'work_date' => '2026-03-02', 'department_id' => $this->department->id]);
            }
        });
        $after = $count();

        expect($after)->toBeLessThanOrEqual($before);
    });
});

describe('monthly register', function (): void {
    it('lays out every day of the month with a status per cell', function (): void {
        $this->actingAsTenantUser($this->hr)
            ->getJson($this->tenantUrl($this->tenant, 'reports/register?month=2026-03'))
            ->assertOk()
            ->assertJsonPath('meta.month', '2026-03')
            ->assertJsonCount(31, 'meta.dates')
            ->assertJsonPath('meta.codes.late', 'L')
            ->assertJsonPath('data.0.employee.employee_code', 'E-1')
            ->assertJsonPath('data.0.days.2026-03-03.status', 'late')
            ->assertJsonPath('data.0.days.2026-03-03.late_minutes', 20)
            ->assertJsonPath('data.0.totals.attended_days', 3)
            ->assertJsonPath('data.1.days.2026-03-06.status', 'late_early');

        $this->getJson($this->tenantUrl($this->tenant, "reports/register?{$this->march}"))->assertJsonValidationErrors('month');
        $this->getJson($this->tenantUrl($this->tenant, 'reports/register?month=2026-3'))->assertJsonValidationErrors('month');
    });
});

describe('exceptions', function (): void {
    it('lists late arrivals, early departures, absences and missing punches', function (): void {
        $this->actingAsTenantUser($this->hr);
        $url = fn (string $q) => $this->tenantUrl($this->tenant, "reports/exceptions?{$this->march}&{$q}");

        $this->getJson($url('type=late'))
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.type', 'late')
            ->assertJsonPath('data.0.work_date', '2026-03-06')
            ->assertJsonPath('data.0.status', 'late_early')
            ->assertJsonPath('data.0.department.name', 'Warehouse')
            ->assertJsonPath('data.1.late_minutes', 20);
        $this->getJson($url('type=late&sort=-late_minutes'))->assertJsonPath('data.0.employee.employee_code', 'E-1');
        $this->getJson($url('type=early_leave'))->assertJsonPath('meta.total', 2);
        $this->getJson($url('type=absent'))->assertJsonPath('meta.total', 2);
        $this->getJson($url('type=incomplete'))->assertJsonPath('meta.total', 0);
        $this->getJson($url(''))->assertJsonValidationErrors('type');

        $this->actingAsTenantUser($this->manager)->getJson($url('type=absent'))
            ->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.employee.employee_code', 'E-1');
    });
});

describe('leave', function (): void {
    it('totals approved leave per employee and leave type, in half days', function (): void {
        $this->approveLeave('2026-03-09', '2026-03-10');
        $this->approveLeave('2026-03-12', '2026-03-12', ['leave_type_id' => $this->unpaid->id, 'half_end' => true]);
        // A pending request: not counted.
        $this->actingAsTenantUser($this->worker)
            ->postJson($this->tenantUrl($this->tenant, 'leave-requests'), $this->leaveBody('2026-03-16', '2026-03-16'))
            ->assertCreated()->assertJsonPath('data.status', 'pending');

        $this->actingAsTenantUser($this->hr)
            ->getJson($this->tenantUrl($this->tenant, "reports/leave?{$this->march}"))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonCount(2, 'meta.leave_types')
            ->assertJsonPath('data.0.employee.employee_code', 'E-1')
            ->assertJsonPath('data.0.half_days', 5)
            ->assertJsonPath('data.0.types.'.$this->annual->id, 4)
            ->assertJsonPath('data.0.types.'.$this->unpaid->id, 1);

        $this->getJson($this->tenantUrl($this->tenant, "reports/leave?{$this->march}&leave_type_id={$this->unpaid->id}"))
            ->assertJsonPath('data.0.half_days', 1);
        $this->getJson($this->tenantUrl($this->tenant, 'reports/leave?from=2026-03-11&to=2026-03-31'))
            ->assertJsonPath('data.0.half_days', 1);
        $this->actingAsTenantUser($this->manager)->getJson($this->tenantUrl($this->tenant, "reports/leave?{$this->march}&department_id={$this->otherDepartment->id}"))
            ->assertJsonPath('meta.total', 0);
    });
});

describe('overtime', function (): void {
    it('summarises overtime per employee', function (): void {
        $this->tenant->run(function (): void {
            OvertimeRecord::factory()->create([
                'employee_id' => $this->employee->id, 'status' => OvertimeStatus::Approved,
                'approved_minutes' => 60, 'approved_weighted_minutes' => 90,
            ]);
            OvertimeRecord::factory()->create(['employee_id' => $this->other->id, 'work_date' => '2026-03-03']);
        });

        $this->actingAsTenantUser($this->hr)
            ->getJson($this->tenantUrl($this->tenant, "reports/overtime?{$this->march}"))
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.employee.employee_code', 'E-1')
            ->assertJsonPath('data.0.approved_weighted_minutes', 90)
            ->assertJsonPath('data.1.pending_minutes', 60);

        $this->actingAsTenantUser($this->manager)->getJson($this->tenantUrl($this->tenant, "reports/overtime?{$this->march}"))
            ->assertJsonPath('meta.total', 1);
    });
});

it('answers 404 for an unknown report and caps the range', function (): void {
    $this->actingAsTenantUser($this->hr);
    $this->getJson($this->tenantUrl($this->tenant, "reports/salaries?{$this->march}"))->assertNotFound();
    $this->getJson($this->tenantUrl($this->tenant, 'reports/attendance-summary?from=2026-01-01&to=2026-04-03'))->assertJsonValidationErrors('to');
});
