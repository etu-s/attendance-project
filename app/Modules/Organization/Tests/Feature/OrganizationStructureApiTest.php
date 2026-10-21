<?php

declare(strict_types=1);

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\Group;
use App\Modules\Organization\Models\Location;
use App\Modules\Organization\Models\OrganizationProfile;
use App\Modules\Organization\Services\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(LocalDate::parse('2026-06-15')->setTime(10, 0));
    $this->tenant = $this->createTenant('acme');
    $this->admin = $this->tenantAdmin($this->tenant);
    $this->hr = $this->createTenantUser($this->tenant, RoleName::HrManager);
    $this->manager = $this->createTenantUser($this->tenant, RoleName::DepartmentManager);
});

describe('organization profile', function (): void {
    it('is seeded from the tenant name at provisioning, with a General department', function (): void {
        $this->tenant->run(function (): void {
            expect(OrganizationProfile::query()->count())->toBe(1)
                ->and(OrganizationProfile::query()->value('legal_name'))->toBe('Acme Inc')
                ->and(Department::query()->pluck('code')->all())->toBe(['GEN']);
        });
    });

    it('is readable by HR and updatable by the Tenant Admin only', function (): void {
        $this->actingAsTenantUser($this->hr)->getJson($this->tenantUrl($this->tenant, 'organization'))
            ->assertOk()->assertJsonPath('data.legal_name', 'Acme Inc');
        $this->actingAsTenantUser($this->hr)->putJson($this->tenantUrl($this->tenant, 'organization'), ['legal_name' => 'X'])
            ->assertForbidden();

        $this->actingAsTenantUser($this->admin)
            ->putJson($this->tenantUrl($this->tenant, 'organization'), ['legal_name' => 'Acme Holdings Ltd', 'email' => 'hr@acme.test'])
            ->assertOk()->assertJsonPath('data.legal_name', 'Acme Holdings Ltd');
    });
});

describe('locations', function (): void {
    it('creates, lists, updates and validates timezones', function (): void {
        $this->actingAsTenantUser($this->hr);

        $id = $this->postJson($this->tenantUrl($this->tenant, 'locations'), ['name' => 'Lahore HQ', 'code' => 'LHE', 'timezone' => 'Asia/Karachi'])
            ->assertCreated()->assertJsonPath('data.timezone', 'Asia/Karachi')->json('data.id');

        $this->postJson($this->tenantUrl($this->tenant, 'locations'), ['name' => 'X', 'code' => 'lhe', 'timezone' => 'Mars/Base'])
            ->assertUnprocessable()->assertJsonValidationErrors(['code', 'timezone']);

        $this->putJson($this->tenantUrl($this->tenant, "locations/{$id}"), ['timezone' => null, 'is_active' => false])
            ->assertOk()->assertJsonPath('data.timezone', null)->assertJsonPath('data.is_active', false);

        $this->getJson($this->tenantUrl($this->tenant, 'locations?is_active=0'))->assertJsonPath('meta.total', 1);
    });

    it('refuses to delete a location that appears in employee history', function (): void {
        [$used, $unused] = $this->tenant->run(function (): array {
            $used = Location::factory()->create();
            Employee::factory()->inDepartment(Department::query()->firstOrFail(), $used)->create();

            return [$used, Location::factory()->create()];
        });
        $this->actingAsTenantUser($this->hr);

        $this->deleteJson($this->tenantUrl($this->tenant, "locations/{$used->id}"))->assertStatus(409)->assertJsonPath('rule', 'location_in_use');
        $this->deleteJson($this->tenantUrl($this->tenant, "locations/{$unused->id}"))->assertNoContent();
    });

    it('is read-only for department managers', function (): void {
        $this->actingAsTenantUser($this->manager);

        $this->getJson($this->tenantUrl($this->tenant, 'locations'))->assertOk();
        $this->postJson($this->tenantUrl($this->tenant, 'locations'), ['name' => 'X', 'code' => 'X'])->assertForbidden();
    });
});

describe('departments', function (): void {
    it('returns the tree in order with depth, path and headcount', function (): void {
        $this->tenant->run(function (): void {
            $ops = Department::factory()->create(['name' => 'Operations']);
            $wh = Department::factory()->childOf($ops)->create(['name' => 'Warehouse']);
            Department::factory()->childOf($ops)->create(['name' => 'Fleet']);
            Employee::factory()->count(2)->inDepartment($wh)->create();
        });

        $rows = $this->actingAsTenantUser($this->hr)->getJson($this->tenantUrl($this->tenant, 'departments'))->assertOk()->json('data');

        expect(array_map(fn ($r) => [$r['path'], $r['depth']], $rows))->toBe([
            ['General', 0],
            ['Operations', 0],
            ['Operations / Fleet', 1],
            ['Operations / Warehouse', 1],
        ])->and($rows[3]['employees_count'])->toBe(2);
    });

    it('prevents cycles in the tree', function (): void {
        [$parent, $child] = $this->tenant->run(function (): array {
            $parent = Department::factory()->create();

            return [$parent, Department::factory()->childOf($parent)->create()];
        });
        $this->actingAsTenantUser($this->hr);

        $this->putJson($this->tenantUrl($this->tenant, "departments/{$parent->id}"), ['parent_id' => $child->id])
            ->assertUnprocessable()->assertJsonPath('rule', 'department_cycle');
        $this->putJson($this->tenantUrl($this->tenant, "departments/{$parent->id}"), ['parent_id' => $parent->id])
            ->assertUnprocessable()->assertJsonPath('rule', 'department_cycle');
    });

    it('refuses to delete departments with sub-departments or history', function (): void {
        [$parent, $child, $empty] = $this->tenant->run(function (): array {
            $parent = Department::factory()->create();
            $child = Department::factory()->childOf($parent)->create();
            Employee::factory()->terminated('2026-03-01')->inDepartment($child)->create();

            return [$parent, $child, Department::factory()->create()];
        });
        $this->actingAsTenantUser($this->hr);

        $this->deleteJson($this->tenantUrl($this->tenant, "departments/{$parent->id}"))->assertStatus(409)->assertJsonPath('rule', 'department_has_children');
        $this->deleteJson($this->tenantUrl($this->tenant, "departments/{$child->id}"))->assertStatus(409)->assertJsonPath('rule', 'department_in_use');
        $this->deleteJson($this->tenantUrl($this->tenant, "departments/{$empty->id}"))->assertNoContent();
    });

    it('sets managers (active users only) and audits the change', function (): void {
        $department = $this->tenant->run(fn () => Department::factory()->create());
        $inactive = $this->createTenantUser($this->tenant, RoleName::DepartmentManager, ['status' => 'inactive']);
        $this->actingAsTenantUser($this->hr);

        $this->putJson($this->tenantUrl($this->tenant, "departments/{$department->id}/managers"), ['user_ids' => [$inactive->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('user_ids');

        $this->putJson($this->tenantUrl($this->tenant, "departments/{$department->id}/managers"), ['user_ids' => [$this->manager->id]])
            ->assertOk()->assertJsonPath('data.managers.0.id', $this->manager->id);

        $this->tenant->run(function () use ($department): void {
            $log = AuditLog::query()->where('auditable_type', 'department')->where('auditable_id', (string) $department->id)
                ->where('action', 'updated')->latest('id')->firstOrFail();
            expect($log->after)->toBe(['managers' => [$this->manager->id]]);
        });
    });

    it('is managed by HR, not by department managers', function (): void {
        $this->actingAsTenantUser($this->manager)
            ->postJson($this->tenantUrl($this->tenant, 'departments'), ['name' => 'X', 'code' => 'X'])
            ->assertForbidden();
    });
});

describe('groups', function (): void {
    it('requires unique codes and priorities and lists by priority with member counts', function (): void {
        $this->actingAsTenantUser($this->hr);

        $this->postJson($this->tenantUrl($this->tenant, 'groups'), ['name' => 'Night', 'code' => 'NIGHT', 'priority' => 20])->assertCreated();
        $this->postJson($this->tenantUrl($this->tenant, 'groups'), ['name' => 'Core', 'code' => 'CORE', 'priority' => 5])->assertCreated();
        $this->postJson($this->tenantUrl($this->tenant, 'groups'), ['name' => 'Dup', 'code' => 'night', 'priority' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors(['code', 'priority']);

        $this->getJson($this->tenantUrl($this->tenant, 'groups'))
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Core')
            ->assertJsonPath('data.0.members_count', 0)
            ->assertJsonPath('data.1.name', 'Night');
    });

    it('refuses to delete a group that has had members', function (): void {
        $group = $this->tenant->run(function (): Group {
            $group = Group::factory()->create();
            $employee = Employee::factory()->inDepartment(Department::query()->firstOrFail())->create();
            app(MembershipService::class)->add($employee, $group, LocalDate::parse('2026-01-01'));

            return $group;
        });

        $this->actingAsTenantUser($this->hr)
            ->deleteJson($this->tenantUrl($this->tenant, "groups/{$group->id}"))
            ->assertStatus(409)->assertJsonPath('rule', 'group_in_use');
    });

    it('sets group managers', function (): void {
        $group = $this->tenant->run(fn () => Group::factory()->create());

        $this->actingAsTenantUser($this->hr)
            ->putJson($this->tenantUrl($this->tenant, "groups/{$group->id}/managers"), ['user_ids' => [$this->manager->id]])
            ->assertOk()->assertJsonPath('data.managers.0.email', $this->manager->email);
    });
});
