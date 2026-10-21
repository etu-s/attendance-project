<?php

declare(strict_types=1);

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\PunchCorrection;
use App\Modules\Attendance\Models\RawPunch;
use App\Modules\Attendance\Tests\Support\AttendanceTesting;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class, AttendanceTesting::class);

/*
 * E-1 (the worker's own record, in the manager's department) forgot to punch
 * out on Monday. The manager is also an employee (M-1) in that department.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-04 12:00:00', 'UTC'));
    $this->attendanceWorkspace();
    $this->hr = $this->createTenantUser($this->tenant, RoleName::HrManager);
    $this->manager = $this->createTenantUser($this->tenant, RoleName::DepartmentManager);
    $this->worker = $this->createTenantUser($this->tenant, RoleName::Employee);

    [$this->managerEmployee, $this->stranger] = $this->tenant->run(function (): array {
        DB::table('department_managers')->insert(['department_id' => $this->department->id, 'user_id' => $this->manager->id]);
        $this->employee->update(['user_id' => $this->worker->id]);

        return [
            Employee::factory()->inDepartment($this->department)->create(['employee_code' => 'M-1', 'hire_date' => '2026-01-01', 'user_id' => $this->manager->id]),
            Employee::factory()->inDepartment(Department::factory()->create())->create(['employee_code' => 'X-1', 'hire_date' => '2026-01-01']),
        ];
    });

    $this->punch('E-1', '2026-03-02 08:57');
    $this->addOut = ['employee_id' => $this->employee->id, 'work_date' => '2026-03-02', 'kind' => 'add', 'punched_at' => '2026-03-02T17:00:00Z', 'reason' => 'Forgot to punch out'];
});

it('lets employees request for themselves only, pending approval', function (): void {
    $this->actingAsTenantUser($this->worker)->postJson($this->tenantUrl($this->tenant, 'punch-corrections'), $this->addOut)
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.can_decide', false);
    expect($this->day('2026-03-02')->status)->toBe(AttendanceStatus::Incomplete);

    $this->postJson($this->tenantUrl($this->tenant, 'punch-corrections'), ['employee_id' => $this->stranger->id] + $this->addOut)->assertForbidden();
    $this->getJson($this->tenantUrl($this->tenant, 'punch-corrections'))->assertOk()->assertJsonCount(1, 'data');
});

it('applies a correction entered by HR at once', function (): void {
    $this->actingAsTenantUser($this->hr)->postJson($this->tenantUrl($this->tenant, 'punch-corrections'), $this->addOut)
        ->assertCreated()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.decided_by.id', $this->hr->id);

    expect($this->day('2026-03-02')->status)->toBe(AttendanceStatus::Present);
});

it('lets the manager approve their people but never their own request', function (): void {
    $id = $this->actingAsTenantUser($this->worker)->postJson($this->tenantUrl($this->tenant, 'punch-corrections'), $this->addOut)->json('data.id');

    $this->actingAsTenantUser($this->manager)->getJson($this->tenantUrl($this->tenant, 'punch-corrections?status=pending'))
        ->assertJsonPath('data.0.can_decide', true);
    $this->postJson($this->tenantUrl($this->tenant, "punch-corrections/{$id}/approve"), ['note' => 'OK'])
        ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.decision_note', 'OK');
    expect($this->day('2026-03-02')->status)->toBe(AttendanceStatus::Present);

    $this->postJson($this->tenantUrl($this->tenant, "punch-corrections/{$id}/reject"))->assertStatus(409);

    $own = $this->postJson($this->tenantUrl($this->tenant, 'punch-corrections'), ['employee_id' => $this->managerEmployee->id] + $this->addOut)
        ->assertCreated()->assertJsonPath('data.status', 'pending')->assertJsonPath('data.can_decide', false)->json('data.id');
    $this->postJson($this->tenantUrl($this->tenant, "punch-corrections/{$own}/approve"))->assertForbidden()->assertJsonPath('rule', 'correction_own');

    $this->actingAsTenantUser($this->hr)->postJson($this->tenantUrl($this->tenant, "punch-corrections/{$own}/reject"), ['note' => 'Not needed'])
        ->assertOk()->assertJsonPath('data.status', 'rejected');
});

it('ignores a wrong punch through an approved correction', function (): void {
    $this->punch('E-1', '2026-03-02 12:00', '2026-03-02 17:02');
    $wrong = $this->tenant->run(fn () => RawPunch::query()->where('punched_at', '2026-03-02 12:00:00')->value('id'));
    $body = ['employee_id' => $this->employee->id, 'work_date' => '2026-03-02', 'kind' => 'ignore', 'raw_punch_id' => $wrong, 'reason' => 'Tapped by mistake'];

    $this->actingAsTenantUser($this->hr)->postJson($this->tenantUrl($this->tenant, 'punch-corrections'), $body)->assertCreated();
    $this->postJson($this->tenantUrl($this->tenant, 'punch-corrections'), $body)->assertUnprocessable()->assertJsonValidationErrors('raw_punch_id');

    $foreign = $this->tenant->run(function (): int {
        $this->punch('X-1', '2026-03-02 09:00');

        return (int) RawPunch::query()->where('employee_identifier', 'X-1')->value('id');
    });
    $this->postJson($this->tenantUrl($this->tenant, 'punch-corrections'), ['raw_punch_id' => $foreign] + $body)
        ->assertUnprocessable()->assertJsonValidationErrors('raw_punch_id');

    expect($this->day('2026-03-02')->punches->pluck('role')->map->value->all())->toBe(['in', 'ignored', 'out']);
});

it('validates dates and times', function (): void {
    $url = $this->tenantUrl($this->tenant, 'punch-corrections');
    $this->actingAsTenantUser($this->hr);

    $this->postJson($url, ['work_date' => '2026-03-09', 'punched_at' => '2026-03-09T09:00:00Z'] + $this->addOut)
        ->assertUnprocessable()->assertJsonValidationErrors('work_date');
    $this->postJson($url, ['punched_at' => '2026-02-20T09:00:00Z'] + $this->addOut)
        ->assertUnprocessable()->assertJsonValidationErrors('punched_at');
    $this->postJson($url, ['punched_at' => '2026-03-02 17:00'] + $this->addOut)
        ->assertUnprocessable()->assertJsonValidationErrors('punched_at');
    $this->postJson($url, ['kind' => 'ignore'] + $this->addOut)
        ->assertUnprocessable()->assertJsonValidationErrors('raw_punch_id');

    expect($this->tenant->run(fn () => PunchCorrection::query()->count()))->toBe(0);
});
