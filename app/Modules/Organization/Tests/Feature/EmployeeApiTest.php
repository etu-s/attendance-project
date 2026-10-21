<?php

declare(strict_types=1);

use App\Modules\Central\Models\Plan;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\UserService;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\Group;
use App\Modules\Organization\Models\Location;
use App\Modules\Organization\Services\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(LocalDate::parse('2026-06-15')->setTime(10, 0));
    $this->tenant = $this->createTenant('acme');
    $this->admin = $this->tenantAdmin($this->tenant);
    $this->hr = $this->createTenantUser($this->tenant, RoleName::HrManager);

    $this->tenant->run(function (): void {
        $this->ops = Department::factory()->create(['name' => 'Operations', 'code' => 'OPS']);
        $this->site = Location::factory()->create(['name' => 'Lahore', 'timezone' => 'Asia/Karachi']);
        $this->night = Group::factory()->create(['name' => 'Night', 'priority' => 10]);
    });
});

function employeePayload(array $overrides = []): array
{
    return array_merge([
        'employee_code' => 'E-100',
        'first_name' => 'Ayesha',
        'last_name' => 'Khan',
        'email' => 'ayesha@acme.test',
        'employment_type' => 'full_time',
        'hire_date' => '2026-02-01',
        'department_id' => test()->ops->id,
        'location_id' => test()->site->id,
        'group_ids' => [test()->night->id],
    ], $overrides);
}

describe('create', function (): void {
    it('creates an employee with an assignment and groups from the hire date', function (): void {
        $this->actingAsTenantUser($this->hr)
            ->postJson($this->tenantUrl($this->tenant, 'employees'), employeePayload())
            ->assertCreated()
            ->assertJsonPath('data.employee_code', 'E-100')
            ->assertJsonPath('data.full_name', 'Ayesha Khan')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.hire_date', '2026-02-01')
            ->assertJsonPath('data.department.name', 'Operations')
            ->assertJsonPath('data.location.timezone', 'Asia/Karachi')
            ->assertJsonPath('data.groups.0.name', 'Night');

        $this->tenant->run(function (): void {
            $raw = DB::table('employee_assignments')->first();
            expect($raw->effective_from)->toBe('2026-02-01')->and($raw->effective_to)->toBeNull()
                ->and(DB::table('employees')->value('hire_date'))->toBe('2026-02-01');
        });
    });

    it('can create a login with the Employee role at the same time', function (): void {
        $response = $this->actingAsTenantUser($this->hr)
            ->postJson($this->tenantUrl($this->tenant, 'employees'), employeePayload(['create_login' => true, 'login_password' => 'welcome123']))
            ->assertCreated();

        $this->tenant->run(function () use ($response): void {
            $user = User::query()->with('roles')->findOrFail($response->json('data.user_id'));
            expect($user->email)->toBe('ayesha@acme.test')->and($user->roleName())->toBe(RoleName::Employee);
        });
    });

    it('refuses a login for an email that already has one', function (): void {
        $this->actingAsTenantUser($this->hr)
            ->postJson($this->tenantUrl($this->tenant, 'employees'), employeePayload([
                'email' => 'admin@acme.test', 'create_login' => true, 'login_password' => 'welcome123',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->tenant->run(fn () => expect(Employee::query()->count())->toBe(0));
    });

    it('validates codes (case-insensitively unique, including deleted employees), dates and references', function (): void {
        $this->tenant->run(function (): void {
            Employee::factory()->inDepartment($this->ops)->create(['employee_code' => 'e-100'])->delete();
        });
        $this->actingAsTenantUser($this->hr);

        $this->postJson($this->tenantUrl($this->tenant, 'employees'), employeePayload())
            ->assertUnprocessable()->assertJsonValidationErrors('employee_code');

        $this->postJson($this->tenantUrl($this->tenant, 'employees'), employeePayload([
            'employee_code' => 'has space', 'hire_date' => '01/02/2026', 'department_id' => 999, 'employment_type' => 'boss',
        ]))->assertUnprocessable()->assertJsonValidationErrors(['employee_code', 'hire_date', 'department_id', 'employment_type']);
    });

    it('enforces the plan employee limit, not counting terminated employees', function (): void {
        $this->tenant->update(['plan_id' => Plan::factory()->create(['max_employees' => 2])->id]);
        $this->tenant->run(function (): void {
            Employee::factory()->inDepartment($this->ops)->create();
            Employee::factory()->terminated('2026-03-01')->inDepartment($this->ops)->create();
        });
        $this->actingAsTenantUser($this->hr);

        $this->postJson($this->tenantUrl($this->tenant, 'employees'), employeePayload())->assertCreated();
        $this->postJson($this->tenantUrl($this->tenant, 'employees'), employeePayload(['employee_code' => 'E-101', 'email' => null]))
            ->assertStatus(409)
            ->assertJsonPath('rule', 'plan_limit_reached');
    });

    it('is forbidden to department managers and employees', function (RoleName $role): void {
        $user = $this->createTenantUser($this->tenant, $role);

        $this->actingAsTenantUser($user)
            ->postJson($this->tenantUrl($this->tenant, 'employees'), employeePayload())
            ->assertForbidden();
    })->with([RoleName::DepartmentManager, RoleName::Employee]);
});

describe('list and show', function (): void {
    beforeEach(function (): void {
        $this->tenant->run(function (): void {
            $this->warehouse = Department::factory()->childOf($this->ops)->create(['name' => 'Warehouse']);
            $other = Department::factory()->create(['name' => 'Finance']);
            Employee::factory()->inDepartment($this->ops)->create(['first_name' => 'Jane', 'last_name' => 'Doe', 'employee_code' => 'A1']);
            Employee::factory()->inDepartment($this->warehouse, $this->site)->create(['first_name' => 'John', 'last_name' => 'Roe', 'employee_code' => 'A2']);
            Employee::factory()->inDepartment($other)->create(['first_name' => 'Jane', 'last_name' => 'Smith', 'employee_code' => 'A3', 'status' => 'inactive']);
        });
        $this->actingAsTenantUser($this->hr);
    });

    it('searches across first and last name', function (): void {
        $this->getJson($this->tenantUrl($this->tenant, 'employees?search=jane+doe'))
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.employee_code', 'A1');
    });

    it('filters by department including sub-departments, by location and status', function (): void {
        $this->getJson($this->tenantUrl($this->tenant, 'employees?department_id='.$this->ops->id))->assertJsonPath('meta.total', 2);
        $this->getJson($this->tenantUrl($this->tenant, 'employees?location_id='.$this->site->id))->assertJsonPath('meta.total', 1);
        $this->getJson($this->tenantUrl($this->tenant, 'employees?status=inactive'))->assertJsonPath('data.0.employee_code', 'A3');
    });

    it('sorts by name and loads relations without N+1 queries', function (): void {
        $this->getJson($this->tenantUrl($this->tenant, 'employees?sort=-name'))
            ->assertOk()->assertJsonPath('data.0.last_name', 'Smith');

        $count = function (): int {
            $queries = 0;
            DB::listen(function () use (&$queries): void {
                $queries++;
            });
            $this->getJson($this->tenantUrl($this->tenant, 'employees?per_page=100'))->assertOk();

            return $queries;
        };

        // Every relation kind (department, location, groups) is present in both measurements,
        // so the only difference between them is the number of rows.
        $addEmployees = fn (int $n) => $this->tenant->run(function () use ($n): void {
            Employee::factory()->count($n)->inDepartment($this->ops, $this->site)->create()
                ->each(fn (Employee $e) => app(MembershipService::class)
                    ->add($e, $this->night, LocalDate::parse('2026-01-01')));
        });

        $addEmployees(2);
        $this->actingAsTenantUser($this->hr);
        $this->getJson($this->tenantUrl($this->tenant, 'employees?per_page=1'))->assertOk(); // warm caches
        $few = $count();

        $addEmployees(20);
        $this->actingAsTenantUser($this->hr);
        $many = $count();

        expect($many)->toBe($few);
    });

    it('shows an employee and their dated history', function (): void {
        $employee = $this->tenant->run(fn () => Employee::query()->where('employee_code', 'A1')->firstOrFail());

        $this->getJson($this->tenantUrl($this->tenant, "employees/{$employee->id}"))
            ->assertOk()->assertJsonPath('data.department.name', 'Operations');

        $this->getJson($this->tenantUrl($this->tenant, "employees/{$employee->id}/history"))
            ->assertOk()
            ->assertJsonPath('data.assignments.0.department.name', 'Operations')
            ->assertJsonPath('data.assignments.0.effective_to', null);
    });
});

describe('lifecycle', function (): void {
    beforeEach(function (): void {
        $this->employee = $this->tenant->run(fn () => Employee::factory()->inDepartment($this->ops)->create(['hire_date' => '2026-01-01']));
        $this->actingAsTenantUser($this->hr);
    });

    it('transfers with an effective date and records history', function (): void {
        $support = $this->tenant->run(fn () => Department::factory()->create(['name' => 'Support']));

        $this->postJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/transfer"), [
            'department_id' => $support->id, 'location_id' => null, 'effective_from' => '2026-05-01',
        ])->assertOk()->assertJsonPath('data.department.name', 'Support');

        $this->getJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/history"))
            ->assertJsonPath('data.assignments.0.effective_from', '2026-05-01')
            ->assertJsonPath('data.assignments.1.effective_to', '2026-04-30');
    });

    it('rejects a transfer before the hire date', function (): void {
        $this->postJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/transfer"), [
            'department_id' => $this->ops->id, 'location_id' => $this->site->id, 'effective_from' => '2025-01-01',
        ])->assertUnprocessable()->assertJsonPath('rule', 'before_hire_date');
    });

    it('terminates, deactivates the login, then reinstates after a gap', function (): void {
        $login = $this->createTenantUser($this->tenant, RoleName::Employee);
        $this->tenant->run(fn () => $this->employee->update(['user_id' => $login->id]));
        $this->actingAsTenantUser($this->hr);

        $this->postJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/terminate"), ['termination_date' => '2026-05-31'])
            ->assertOk()
            ->assertJsonPath('data.status', 'terminated')
            ->assertJsonPath('data.termination_date', '2026-05-31')
            ->assertJsonPath('data.department', null);

        $this->tenant->run(fn () => expect(User::query()->find($login->id)->status)->toBe(UserStatus::Inactive));

        $this->postJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/reinstate"), ['effective_from' => '2026-05-31'])
            ->assertUnprocessable()->assertJsonPath('rule', 'rehire_before_termination');

        $this->postJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/reinstate"), ['effective_from' => '2026-06-10'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.termination_date', null)
            ->assertJsonPath('data.department.name', 'Operations');

        $this->getJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/history"))
            ->assertJsonCount(2, 'data.assignments')
            ->assertJsonPath('data.assignments.0.effective_from', '2026-06-10')
            ->assertJsonPath('data.assignments.1.effective_to', '2026-05-31');
    });

    it('does not let anyone terminate their own employee record', function (): void {
        $this->tenant->run(fn () => $this->employee->update(['user_id' => $this->hr->id]));

        $this->postJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/terminate"), ['termination_date' => '2026-05-31'])
            ->assertForbidden()->assertJsonPath('rule', 'self_termination');
    });

    it('cannot set status terminated through a plain update', function (): void {
        $this->putJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}"), ['status' => 'terminated'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->putJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}"), ['status' => 'inactive', 'job_title' => 'Lead'])
            ->assertOk()->assertJsonPath('data.status', 'inactive')->assertJsonPath('data.job_title', 'Lead');
    });

    it('corrects the hire date and moves the start of history with it', function (): void {
        $this->putJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}"), ['hire_date' => '2025-12-15'])
            ->assertOk()->assertJsonPath('data.hire_date', '2025-12-15');

        $this->getJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/history"))
            ->assertJsonPath('data.assignments.0.effective_from', '2025-12-15');
    });

    it('joins and leaves groups with dates', function (): void {
        $this->postJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/groups"), [
            'group_id' => $this->night->id, 'effective_from' => '2026-03-01',
        ])->assertOk()->assertJsonPath('data.groups.0.name', 'Night');

        $this->postJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/groups/{$this->night->id}/end"), [
            'effective_to' => '2026-06-01',
        ])->assertOk()->assertJsonCount(0, 'data.groups');
    });

    it('links a login once and frees it when the login is deleted', function (): void {
        $login = $this->createTenantUser($this->tenant, RoleName::Employee);
        $other = $this->tenant->run(fn () => Employee::factory()->inDepartment($this->ops)->create());
        $this->actingAsTenantUser($this->hr);

        $this->putJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/user"), ['user_id' => $login->id])
            ->assertOk()->assertJsonPath('data.user_id', $login->id);

        $this->putJson($this->tenantUrl($this->tenant, "employees/{$other->id}/user"), ['user_id' => $login->id])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');

        $this->tenant->run(function () use ($login): void {
            app(UserService::class)->delete(User::query()->with('roles')->findOrFail($login->id), User::query()->with('roles')->findOrFail(1));
            expect($this->employee->fresh()->user_id)->toBeNull();
        });
    });

    it('soft deletes (admin only) and audits changes', function (): void {
        $this->deleteJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}"))->assertForbidden();

        $this->actingAsTenantUser($this->admin)
            ->deleteJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}"))
            ->assertNoContent();

        $this->tenant->run(function (): void {
            expect(Employee::withTrashed()->find($this->employee->id)?->trashed())->toBeTrue()
                ->and(AuditLog::query()->where('auditable_type', 'employee')->where('action', 'deleted')->exists())->toBeTrue();
        });
    });
});

describe('self service', function (): void {
    it('returns the signed-in user\'s own employee record', function (): void {
        $login = $this->createTenantUser($this->tenant, RoleName::Employee);
        $this->tenant->run(fn () => Employee::factory()->inDepartment($this->ops)->create(['user_id' => $login->id, 'employee_code' => 'ME1']));

        $this->actingAsTenantUser($login)
            ->getJson($this->tenantUrl($this->tenant, 'me/employee'))
            ->assertOk()->assertJsonPath('data.employee_code', 'ME1');

        $this->actingAsTenantUser($this->admin)->getJson($this->tenantUrl($this->tenant, 'me/employee'))->assertNotFound();
    });
});
