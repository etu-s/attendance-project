<?php

declare(strict_types=1);

use App\Modules\Core\Services\SettingsService;
use App\Modules\Core\Tenancy\TenantSqliteDatabaseManager;
use App\Modules\Leave\Tests\Support\LeaveTesting;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Overtime\Models\OvertimeRecord;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class, LeaveTesting::class);

/*
 * E-1 (the worker's record, in the manager's department) has 60 minutes of
 * overtime on Monday and 120 on Tuesday; the manager (M-1) has 60 on Monday.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-04 12:00:00', 'UTC'));
    $this->leaveWorkspace();
    $this->managerEmployee = $this->tenant->run(fn () => Employee::factory()->inDepartment($this->department)->create([
        'employee_code' => 'M-1', 'hire_date' => '2026-01-01', 'user_id' => $this->manager->id,
    ]));

    $this->punch('E-1', '2026-03-02 08:55', '2026-03-02 18:10', '2026-03-03 09:00', '2026-03-03 19:05');
    $this->punch('M-1', '2026-03-02 09:00', '2026-03-02 18:05');

    [$this->monday, $this->tuesday, $this->managers] = $this->tenant->run(fn () => [
        OvertimeRecord::query()->where('employee_id', $this->employee->id)->where('work_date', '2026-03-02')->value('id'),
        OvertimeRecord::query()->where('employee_id', $this->employee->id)->where('work_date', '2026-03-03')->value('id'),
        OvertimeRecord::query()->where('employee_id', $this->managerEmployee->id)->value('id'),
    ]);
});

it('lists overtime by visibility and shows employees their own', function (): void {
    $this->actingAsTenantUser($this->hr)->getJson($this->tenantUrl($this->tenant, 'overtime'))
        ->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('data.0.work_date', '2026-03-03');
    $this->getJson($this->tenantUrl($this->tenant, 'overtime?status=pending&from=2026-03-03&to=2026-03-03'))
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.calculated_minutes', 120);

    $stranger = $this->tenant->run(fn () => Employee::factory()->inDepartment(Department::factory()->create())->create(['employee_code' => 'X-1', 'hire_date' => '2026-01-01']));
    $this->punch('X-1', '2026-03-02 09:00', '2026-03-02 18:00');
    $this->actingAsTenantUser($this->manager)->getJson($this->tenantUrl($this->tenant, 'overtime'))->assertJsonCount(3, 'data');
    $this->actingAsTenantUser($this->hr)->getJson($this->tenantUrl($this->tenant, 'overtime'))->assertJsonCount(4, 'data');
    expect($stranger->id)->toBeInt();

    $this->actingAsTenantUser($this->worker)->getJson($this->tenantUrl($this->tenant, 'overtime'))->assertForbidden();
    $this->getJson($this->tenantUrl($this->tenant, 'me/overtime?from=2026-03-01&to=2026-03-31'))->assertOk()->assertJsonCount(2, 'data');
});

it('approves fewer minutes with proportional weight, and rejects', function (): void {
    $this->actingAsTenantUser($this->manager);

    $this->postJson($this->tenantUrl($this->tenant, "overtime/{$this->tuesday}/approve"), ['minutes' => 200])
        ->assertUnprocessable()->assertJsonValidationErrors('minutes');
    $this->postJson($this->tenantUrl($this->tenant, "overtime/{$this->tuesday}/approve"), ['minutes' => 60, 'note' => 'Only the first hour was agreed'])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.approved_minutes', 60)
        ->assertJsonPath('data.approved_weighted_minutes', 90);
    $this->postJson($this->tenantUrl($this->tenant, "overtime/{$this->tuesday}/reject"))->assertStatus(409);

    $this->postJson($this->tenantUrl($this->tenant, "overtime/{$this->monday}/reject"), ['note' => 'Not requested'])
        ->assertOk()->assertJsonPath('data.status', 'rejected');
});

it('never lets anyone decide their own overtime', function (): void {
    $this->actingAsTenantUser($this->manager)
        ->getJson($this->tenantUrl($this->tenant, 'overtime?employee_id='.$this->managerEmployee->id))
        ->assertJsonPath('data.0.can_decide', false);
    $this->postJson($this->tenantUrl($this->tenant, "overtime/{$this->managers}/approve"))->assertForbidden()->assertJsonPath('rule', 'overtime_own');

    $this->actingAsTenantUser($this->worker)->postJson($this->tenantUrl($this->tenant, "overtime/{$this->monday}/approve"))->assertForbidden();
});

it('approves in batches, skipping what the approver may not decide', function (): void {
    $this->actingAsTenantUser($this->manager)
        ->postJson($this->tenantUrl($this->tenant, 'overtime/approve-batch'), ['ids' => [$this->monday, $this->tuesday, $this->managers]])
        ->assertOk()
        ->assertJsonPath('data', ['approved' => 2, 'skipped' => 1]);

    $this->actingAsTenantUser($this->hr)->getJson($this->tenantUrl($this->tenant, 'overtime/summary?from=2026-03-01&to=2026-03-31'))
        ->assertOk()
        ->assertJsonPath('data.0.employee.employee_code', 'E-1')
        ->assertJsonPath('data.0.approved_minutes', 180)
        ->assertJsonPath('data.0.approved_weighted_minutes', 270)
        ->assertJsonPath('data.0.approved_workday_minutes', 180)
        ->assertJsonPath('data.1.pending_minutes', 60)
        ->assertJsonPath('data.1.pending_count', 1);
});

it('records manual overtime for HR, approved at once for others', function (): void {
    $url = $this->tenantUrl($this->tenant, 'overtime-entries');
    $body = ['employee_id' => $this->employee->id, 'work_date' => '2026-03-01', 'minutes' => 120, 'kind' => 'rest_day', 'reason' => 'Stock count on Sunday'];

    $id = $this->actingAsTenantUser($this->hr)->postJson($url, $body)
        ->assertCreated()
        ->assertJsonPath('data.source', 'manual')
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.weighted_minutes', 240)
        ->json('data.id');

    $this->postJson($url, $body)->assertUnprocessable()->assertJsonValidationErrors('work_date');
    $this->postJson($url, ['work_date' => '2026-03-20'] + $body)->assertUnprocessable()->assertJsonValidationErrors('work_date');
    $this->putJson($this->tenantUrl($this->tenant, "overtime-entries/{$id}"), ['minutes' => 60])->assertStatus(409);
    $this->putJson($this->tenantUrl($this->tenant, "overtime-entries/{$this->monday}"), ['minutes' => 60])->assertStatus(409)->assertJsonPath('rule', 'overtime_calculated');

    $this->actingAsTenantUser($this->manager)->postJson($url, ['work_date' => '2026-02-28'] + $body)->assertForbidden();
});

it('lets HR edit and delete a pending manual entry', function (): void {
    $hrEmployee = $this->tenant->run(fn () => Employee::factory()->inDepartment($this->department)->create(['user_id' => $this->hr->id, 'hire_date' => '2026-01-01']));
    $id = $this->actingAsTenantUser($this->hr)
        ->postJson($this->tenantUrl($this->tenant, 'overtime-entries'), [
            'employee_id' => $hrEmployee->id, 'work_date' => '2026-03-01', 'minutes' => 90, 'kind' => 'workday', 'reason' => 'Late release',
        ])->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');

    $this->putJson($this->tenantUrl($this->tenant, "overtime-entries/{$id}"), ['minutes' => 60, 'kind' => 'holiday'])
        ->assertOk()->assertJsonPath('data.holiday_minutes', 60)->assertJsonPath('data.weighted_minutes', 120);
    $this->deleteJson($this->tenantUrl($this->tenant, "overtime-entries/{$id}"))->assertNoContent();
});

it('recalculates with new rates on request', function (): void {
    $this->tenant->run(fn () => app(SettingsService::class)->update(['overtime.rate_workday' => 200]));

    $this->actingAsTenantUser($this->hr)
        ->postJson($this->tenantUrl($this->tenant, 'overtime/recalculate'), ['from' => '2026-03-01', 'to' => '2026-03-04'])
        ->assertStatus(202);
    $this->getJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/overtime?from=2026-03-02&to=2026-03-02"))
        ->assertOk()->assertJsonPath('data.0.weighted_minutes', 120)->assertJsonPath('data.0.rates.workday', 200);

    $this->postJson($this->tenantUrl($this->tenant, 'overtime/recalculate'), ['from' => '2026-01-01', 'to' => '2026-06-01'])->assertUnprocessable();
    $this->actingAsTenantUser($this->manager)
        ->postJson($this->tenantUrl($this->tenant, 'overtime/recalculate'), ['from' => '2026-03-01', 'to' => '2026-03-04'])
        ->assertForbidden();
});

it('keeps tenant B\'s overtime out of tenant A', function (): void {
    $acme = $this->tenant;
    $this->leaveWorkspace('globex');
    $this->punch('E-1', '2026-03-02 09:00', '2026-03-02 20:00');

    expect($this->tenant->run(fn () => OvertimeRecord::query()->count()))->toBe(1);
    $pdo = new PDO('sqlite:'.app(TenantSqliteDatabaseManager::class)->path($acme->database()->getName()));
    expect((int) $pdo->query('select count(*) from overtime_records where calculated_minutes = 180')->fetchColumn())->toBe(0)
        ->and((int) $pdo->query('select count(*) from overtime_records')->fetchColumn())->toBe(3);
    $pdo = null;
});
