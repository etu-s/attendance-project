<?php

declare(strict_types=1);

use App\Modules\Core\Enums\RoleName;
use App\Modules\Leave\Tests\Support\LeaveTesting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class, LeaveTesting::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-04 12:00:00', 'UTC'));
    $this->leaveWorkspace();
});

it('manages leave types in days, for HR only', function (): void {
    $url = $this->tenantUrl($this->tenant, 'leave-types');

    $id = $this->actingAsTenantUser($this->hr)->postJson($url, [
        'name' => 'Sick leave', 'code' => 'sl', 'accrual' => 'yearly', 'entitlement_days' => 10, 'carry_forward_max_days' => null, 'negative_limit_days' => 1.5,
    ])->assertCreated()->assertJsonPath('data.entitlement_days', 10)->assertJsonPath('data.negative_limit_days', 1.5)->json('data.id');

    $this->postJson($url, ['name' => 'Dup', 'code' => 'SL'])->assertUnprocessable()->assertJsonValidationErrors(['code']);
    $this->postJson($url, ['name' => 'X', 'code' => 'X1', 'accrual' => 'yearly', 'entitlement_days' => 1.25])->assertUnprocessable()->assertJsonValidationErrors('entitlement_days');

    // Unlimited types keep no balance: entitlement and carry-over are cleared.
    $this->putJson($this->tenantUrl($this->tenant, "leave-types/{$id}"), ['unlimited' => true])
        ->assertOk()->assertJsonPath('data.entitlement_days', 0)->assertJsonPath('data.accrual', 'none');

    $this->deleteJson($this->tenantUrl($this->tenant, "leave-types/{$id}"))->assertNoContent();
    $this->deleteJson($this->tenantUrl($this->tenant, "leave-types/{$this->annual->id}"))->assertStatus(409)->assertJsonPath('rule', 'leave_type_in_use');

    $this->putJson($this->tenantUrl($this->tenant, "leave-types/{$this->annual->id}"), ['is_active' => false])->assertOk();
    $this->actingAsTenantUser($this->worker)->getJson($url)->assertOk()->assertJsonCount(1, 'data');
    $this->actingAsTenantUser($this->hr)->getJson($url.'?all=1')->assertJsonCount(2, 'data');
    $this->actingAsTenantUser($this->manager)->postJson($url, ['name' => 'X', 'code' => 'X2', 'accrual' => 'none'])->assertForbidden();
});

it('shows balances and the ledger, and records adjustments with a reason', function (): void {
    $this->actingAsTenantUser($this->hr)->postJson($this->tenantUrl($this->tenant, 'leave-adjustments'), [
        'employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id, 'days' => -1.5, 'reason' => 'Advance last year',
    ])->assertCreated()->assertJsonPath('data.kind', 'adjustment')->assertJsonPath('data.days', -1.5);

    $this->postJson($this->tenantUrl($this->tenant, 'leave-adjustments'), [
        'employee_id' => $this->employee->id, 'leave_type_id' => $this->unpaid->id, 'days' => 1, 'reason' => 'Nope',
    ])->assertUnprocessable()->assertJsonPath('rule', 'leave_unlimited');
    $this->postJson($this->tenantUrl($this->tenant, 'leave-adjustments'), [
        'employee_id' => $this->employee->id, 'leave_type_id' => $this->annual->id, 'days' => 0, 'reason' => 'Zero',
    ])->assertUnprocessable()->assertJsonValidationErrors('days');

    $this->getJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/leave-balances"))
        ->assertOk()
        ->assertJsonPath('leave_year', 2026)
        ->assertJsonPath('data.0.type.code', 'AL')
        ->assertJsonPath('data.0.entitled', 14)
        ->assertJsonPath('data.0.adjusted', -1.5)
        ->assertJsonPath('data.0.available', 12.5)
        ->assertJsonPath('data.1.available', null);

    $this->getJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/leave-ledger"))
        ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.1.reason', 'Advance last year');

    $this->actingAsTenantUser($this->worker)->getJson($this->tenantUrl($this->tenant, 'me/leave-balances'))->assertOk()->assertJsonPath('data.0.available', 12.5);
    $this->getJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/leave-ledger"))->assertForbidden();
    $this->actingAsTenantUser($this->manager)->getJson($this->tenantUrl($this->tenant, 'me/leave-balances'))->assertNotFound();
    $this->actingAsTenantUser($this->createTenantUser($this->tenant, RoleName::DepartmentManager))
        ->getJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/leave-balances"))
        ->assertForbidden();
});
