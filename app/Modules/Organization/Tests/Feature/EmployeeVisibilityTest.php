<?php

declare(strict_types=1);

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\Group;
use App\Modules\Organization\Services\AssignmentService;
use App\Modules\Organization\Services\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * Department managers see employees currently in the departments they manage
 * (and below), or currently in groups they manage. Nobody else.
 */
beforeEach(function (): void {
    $this->travelTo(LocalDate::parse('2026-06-15')->setTime(10, 0));
    $this->tenant = $this->createTenant('acme');
    $this->manager = $this->createTenantUser($this->tenant, RoleName::DepartmentManager);

    $this->tenant->run(function (): void {
        $ops = Department::factory()->create(['name' => 'Operations']);
        $warehouse = Department::factory()->childOf($ops)->create(['name' => 'Warehouse']);
        $finance = Department::factory()->create(['name' => 'Finance']);
        $crew = Group::factory()->create(['name' => 'Night crew']);

        DB::table('department_managers')->insert(['department_id' => $ops->id, 'user_id' => $this->manager->id]);
        DB::table('group_managers')->insert(['group_id' => $crew->id, 'user_id' => $this->manager->id]);

        $this->inOps = Employee::factory()->inDepartment($ops)->create(['employee_code' => 'OPS1']);
        $this->inWarehouse = Employee::factory()->inDepartment($warehouse)->create(['employee_code' => 'WH1']);
        $this->inFinance = Employee::factory()->inDepartment($finance)->create(['employee_code' => 'FIN1']);
        $this->financeInCrew = Employee::factory()->inDepartment($finance)->create(['employee_code' => 'FIN2']);
        app(MembershipService::class)->add($this->financeInCrew, $crew, LocalDate::parse('2026-01-01'));

        // Moved out of Operations last month: no longer visible to its manager.
        $this->movedOut = Employee::factory()->inDepartment($ops)->create(['employee_code' => 'OLD1']);
        app(AssignmentService::class)->transfer($this->movedOut, $finance->id, null, LocalDate::parse('2026-05-01'));
    });
});

it('lists only employees in managed departments (with sub-departments) and groups', function (): void {
    $codes = $this->actingAsTenantUser($this->manager)
        ->getJson($this->tenantUrl($this->tenant, 'employees?per_page=50'))
        ->assertOk()
        ->json('data.*.employee_code');

    expect($codes)->toEqualCanonicalizing(['OPS1', 'WH1', 'FIN2']);
});

it('refuses to show or edit employees outside the manager\'s scope', function (): void {
    $this->actingAsTenantUser($this->manager);

    $this->getJson($this->tenantUrl($this->tenant, "employees/{$this->inWarehouse->id}"))->assertOk();
    $this->getJson($this->tenantUrl($this->tenant, "employees/{$this->inFinance->id}"))->assertForbidden();
    $this->getJson($this->tenantUrl($this->tenant, "employees/{$this->movedOut->id}/history"))->assertForbidden();
    $this->putJson($this->tenantUrl($this->tenant, "employees/{$this->inOps->id}"), ['job_title' => 'X'])->assertForbidden();
});

it('cannot widen the scope through filters', function (): void {
    $finance = $this->tenant->run(fn () => Department::query()->where('name', 'Finance')->firstOrFail());

    $codes = $this->actingAsTenantUser($this->manager)
        ->getJson($this->tenantUrl($this->tenant, "employees?department_id={$finance->id}"))
        ->json('data.*.employee_code');

    expect($codes)->toBe(['FIN2']);
});

it('lets HR see everyone', function (): void {
    $hr = $this->createTenantUser($this->tenant, RoleName::HrManager);

    $this->actingAsTenantUser($hr)
        ->getJson($this->tenantUrl($this->tenant, 'employees'))
        ->assertJsonPath('meta.total', 5);
});

it('gives plain employees no employee list, only their own record', function (): void {
    $user = $this->createTenantUser($this->tenant, RoleName::Employee);
    $this->tenant->run(fn () => $this->inFinance->update(['user_id' => $user->id]));

    $this->actingAsTenantUser($user)->getJson($this->tenantUrl($this->tenant, 'employees'))->assertForbidden();
    $this->actingAsTenantUser($user)->getJson($this->tenantUrl($this->tenant, "employees/{$this->inFinance->id}"))->assertOk();
    $this->actingAsTenantUser($user)->getJson($this->tenantUrl($this->tenant, "employees/{$this->inOps->id}"))->assertForbidden();
});
