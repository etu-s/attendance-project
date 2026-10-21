<?php

declare(strict_types=1);

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Tests\Support\AttendanceTesting;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class, AttendanceTesting::class);

/*
 * E-1 (ops) is late on Monday and present on Tuesday; M-1 is in the
 * manager's department and present on Monday.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-04 12:00:00', 'UTC'));
    $this->attendanceWorkspace();
    $this->hr = $this->createTenantUser($this->tenant, RoleName::HrManager);
    $this->manager = $this->createTenantUser($this->tenant, RoleName::DepartmentManager);
    $this->worker = $this->createTenantUser($this->tenant, RoleName::Employee);

    $this->managed = $this->tenant->run(function (): Employee {
        $this->employee->update(['user_id' => $this->worker->id, 'first_name' => 'Ayesha', 'last_name' => 'Khan']);
        $managed = Department::factory()->create();
        DB::table('department_managers')->insert(['department_id' => $managed->id, 'user_id' => $this->manager->id]);

        return Employee::factory()->inDepartment($managed)->create(['employee_code' => 'M-1', 'hire_date' => '2026-01-01']);
    });

    $this->punch('E-1', '2026-03-02 09:20', '2026-03-02 17:00', '2026-03-03 08:58', '2026-03-03 17:01');
    $this->punch('M-1', '2026-03-02 08:50', '2026-03-02 17:05');
});

it('lists days with filters, search and server-side sorting', function (): void {
    $url = fn (string $query) => $this->tenantUrl($this->tenant, 'attendance?'.$query);

    $this->actingAsTenantUser($this->hr)->getJson($url('date=2026-03-02'))
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2);

    $this->getJson($url('from=2026-03-02&to=2026-03-03&status=late,absent'))
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.work_date', '2026-03-03')
        ->assertJsonPath('data.1.status', 'late')
        ->assertJsonPath('data.1.employee.employee_code', 'E-1');

    $this->getJson($url('date=2026-03-02&search=khan'))->assertJsonCount(1, 'data');
    $this->getJson($url("date=2026-03-02&department_id={$this->department->id}"))->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_id', $this->employee->id);
    $this->getJson($url('date=2026-03-02&sort=-late_minutes'))->assertJsonPath('data.0.late_minutes', 20);
    $this->getJson($url('status=sleeping'))->assertUnprocessable();
});

it('shows managers only their people and refuses employees', function (): void {
    $this->actingAsTenantUser($this->manager)->getJson($this->tenantUrl($this->tenant, 'attendance?date=2026-03-02'))
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_id', $this->managed->id);

    $this->actingAsTenantUser($this->manager)->getJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/attendance?from=2026-03-01&to=2026-03-04"))
        ->assertForbidden();

    $this->actingAsTenantUser($this->worker)->getJson($this->tenantUrl($this->tenant, 'attendance'))->assertForbidden();
});

it('summarises a date by status', function (): void {
    $this->actingAsTenantUser($this->hr)->getJson($this->tenantUrl($this->tenant, 'attendance/summary?date=2026-03-02'))
        ->assertOk()
        ->assertJsonPath('data.counts.late', 1)
        ->assertJsonPath('data.counts.present', 1)
        ->assertJsonPath('data.counts.absent', 0)
        ->assertJsonPath('data.total', 2);

    $this->actingAsTenantUser($this->manager)->getJson($this->tenantUrl($this->tenant, 'attendance/summary?date=2026-03-02'))
        ->assertJsonPath('data.total', 1);
});

it('shows a day with periods, punches and corrections to HR and to the employee', function (): void {
    $id = $this->day('2026-03-02')->id;
    $managedDay = $this->day('2026-03-02', $this->managed)->id;

    $this->actingAsTenantUser($this->hr)->getJson($this->tenantUrl($this->tenant, "attendance/{$id}"))
        ->assertOk()
        ->assertJsonPath('data.status', 'late')
        ->assertJsonCount(1, 'data.periods')
        ->assertJsonPath('data.punches.0.role', 'in')
        ->assertJsonPath('data.punches.0.source', 'Test terminal')
        ->assertJsonPath('corrections', []);

    $this->actingAsTenantUser($this->worker)->getJson($this->tenantUrl($this->tenant, "attendance/{$id}"))->assertOk();
    $this->actingAsTenantUser($this->worker)->getJson($this->tenantUrl($this->tenant, "attendance/{$managedDay}"))->assertForbidden();
});

it('gives each employee their own range, and HR anyone\'s', function (): void {
    $this->actingAsTenantUser($this->worker)->getJson($this->tenantUrl($this->tenant, 'me/attendance?from=2026-03-01&to=2026-03-04'))
        ->assertOk()
        ->assertJsonPath('data.0.work_date', '2026-03-01')
        ->assertJsonPath('data.1.status', 'late');

    $this->actingAsTenantUser($this->hr)->getJson($this->tenantUrl($this->tenant, "employees/{$this->managed->id}/attendance?from=2026-03-02&to=2026-03-02"))
        ->assertOk()->assertJsonCount(1, 'data');
    $this->getJson($this->tenantUrl($this->tenant, "employees/{$this->managed->id}/attendance?from=2026-01-01&to=2026-03-31"))->assertUnprocessable();

    $this->actingAsTenantUser($this->manager)->getJson($this->tenantUrl($this->tenant, 'me/attendance?from=2026-03-01&to=2026-03-04'))->assertNotFound();
});

it('queues a recalculation for HR only', function (): void {
    $this->tenant->run(fn () => AttendanceDay::query()->where('employee_id', $this->employee->id)->where('work_date', '2026-03-02')->update(['status' => 'absent', 'fingerprint' => 'stale']));

    $this->actingAsTenantUser($this->hr)
        ->postJson($this->tenantUrl($this->tenant, 'attendance/recalculate'), ['from' => '2026-03-02', 'to' => '2026-03-02', 'employee_id' => $this->employee->id])
        ->assertStatus(202);
    expect($this->day('2026-03-02')->status->value)->toBe('late');

    $this->postJson($this->tenantUrl($this->tenant, 'attendance/recalculate'), ['from' => '2026-01-01', 'to' => '2026-06-01'])->assertUnprocessable();
    $this->actingAsTenantUser($this->manager)
        ->postJson($this->tenantUrl($this->tenant, 'attendance/recalculate'), ['from' => '2026-03-02', 'to' => '2026-03-02'])
        ->assertForbidden();
});

it('keeps another tenant\'s attendance out of reach', function (): void {
    $acme = $this->tenant;
    $acmeDay = $this->day('2026-03-02')->id;

    $this->attendanceWorkspace(subdomain: 'globex');
    $globexHr = $this->createTenantUser($this->tenant, RoleName::HrManager);

    $this->actingAsTenantUser($globexHr)->getJson($this->tenantUrl($this->tenant, "attendance/{$acmeDay}"))->assertNotFound();
    $this->getJson($this->tenantUrl($this->tenant, 'attendance?date=2026-03-02'))->assertJsonCount(0, 'data');

    expect($acme->run(fn () => AttendanceDay::query()->count()))->toBeGreaterThan(0);
});
