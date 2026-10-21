<?php

declare(strict_types=1);

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Tenancy\TenantSqliteDatabaseManager;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\Group;
use App\Modules\Organization\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(LocalDate::parse('2026-06-15')->setTime(10, 0));
    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');

    $this->globexEmployee = $this->globex->run(function (): Employee {
        $department = Department::factory()->create(['name' => 'Globex Secret Dept', 'code' => 'GSD']);
        Location::factory()->create(['name' => 'Globex Tower', 'code' => 'GT']);
        Group::factory()->create(['name' => 'Globex Crew', 'code' => 'GC']);

        return Employee::factory()->inDepartment($department)->create(['employee_code' => 'GLOBEX-1', 'first_name' => 'Hank']);
    });
});

it('keeps tenant B\'s organization data out of tenant A\'s file', function (): void {
    $path = app(TenantSqliteDatabaseManager::class)->path($this->acme->database()->getName());
    $pdo = new PDO('sqlite:'.$path);

    foreach ([
        "select count(*) from employees where employee_code like 'GLOBEX%'",
        "select count(*) from departments where name like 'Globex%'",
        "select count(*) from locations where name like 'Globex%'",
        "select count(*) from groups where name like 'Globex%'",
        'select count(*) from employee_assignments',
        "select count(*) from audit_logs where after like '%GLOBEX-1%'",
    ] as $sql) {
        expect((int) $pdo->query($sql)->fetchColumn())->toBe(0, $sql);
    }

    $pdo = null;
});

it('does not resolve another tenant\'s employee ids or list their employees', function (): void {
    $admin = $this->tenantAdmin($this->acme);
    $this->actingAsTenantUser($admin);

    $this->getJson($this->tenantUrl($this->acme, 'employees'))->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson($this->tenantUrl($this->acme, "employees/{$this->globexEmployee->id}"))->assertNotFound();

    $hr = $this->createTenantUser($this->globex, RoleName::HrManager);
    $this->actingAsTenantUser($hr)
        ->getJson($this->tenantUrl($this->globex, 'employees'))
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.employee_code', 'GLOBEX-1');
});
