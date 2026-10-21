<?php

declare(strict_types=1);

use App\Modules\Central\Models\Plan;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\EmployeeImport;
use App\Modules\Organization\Models\Group;
use App\Modules\Organization\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

/*
 * The queue is `sync` in tests, so the import job runs inside the request.
 */
beforeEach(function (): void {
    $this->travelTo(LocalDate::parse('2026-06-15')->setTime(10, 0));
    $this->tenant = $this->createTenant('acme');
    $this->hr = $this->createTenantUser($this->tenant, RoleName::HrManager);

    $this->tenant->run(function (): void {
        Department::factory()->create(['code' => 'OPS', 'name' => 'Operations']);
        Location::factory()->create(['code' => 'LHE']);
        Group::factory()->create(['code' => 'NIGHT', 'priority' => 10]);
        Group::factory()->create(['code' => 'CORE', 'priority' => 5]);
    });
});

function csvUpload(string $contents): UploadedFile
{
    return UploadedFile::fake()->createWithContent('employees.csv', $contents);
}

it('creates valid rows and reports invalid ones by line', function (): void {
    $csv = "\xEF\xBB\xBF".implode("\n", [
        'Employee_Code,first_name,last_name,email,hire_date,department_code,location_code,group_codes',
        'E1,Ayesha,Khan,ayesha@acme.test,2026-01-05,ops,LHE,night;core',
        'E2,Bilal,,bilal@acme.test,2026-01-05,OPS,,',
        'E3,Sara,Ali,,05/01/2026,OPS,,',
        'E4,Omar,Raza,,2026-02-01,NOPE,,',
        'e1,Dup,Code,,2026-02-01,OPS,,',
        '',
        'E5,Hina,Shah,,2026-03-01,OPS,,UNKNOWN',
        'E6,Zain,Malik,,2026-03-01,OPS,,',
    ]);

    $response = $this->actingAsTenantUser($this->hr)
        ->post($this->tenantUrl($this->tenant, 'employees/import'), ['file' => csvUpload($csv)])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.total_rows', 7)
        ->assertJsonPath('data.created_count', 2)
        ->assertJsonPath('data.failed_count', 5);

    expect(collect($response->json('data.errors'))->pluck('row')->all())->toBe([3, 4, 5, 6, 8]);

    $this->tenant->run(function (): void {
        $e1 = Employee::query()->where('employee_code', 'E1')->firstOrFail();
        expect(Employee::query()->pluck('employee_code')->sort()->values()->all())->toBe(['E1', 'E6'])
            ->and($e1->memberships()->count())->toBe(2)
            ->and(EmployeeImport::query()->value('contents'))->toBeNull();
    });
});

it('fails the whole import when required columns are missing', function (): void {
    $this->actingAsTenantUser($this->hr)
        ->post($this->tenantUrl($this->tenant, 'employees/import'), ['file' => csvUpload("employee_code,first_name\nE1,Ayesha")])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'failed')
        ->assertJsonPath('data.errors.0.messages.0', 'Missing columns: last_name, hire_date, department_code.');
});

it('stops creating rows once the plan limit is reached', function (): void {
    $this->tenant->update(['plan_id' => Plan::factory()->create(['max_employees' => 1])->id]);
    $csv = "employee_code,first_name,last_name,hire_date,department_code\nE1,A,B,2026-01-01,OPS\nE2,C,D,2026-01-01,OPS";

    $response = $this->actingAsTenantUser($this->hr)
        ->post($this->tenantUrl($this->tenant, 'employees/import'), ['file' => csvUpload($csv)])
        ->assertJsonPath('data.created_count', 1)
        ->assertJsonPath('data.failed_count', 1);

    expect($response->json('data.errors.0.messages.0'))->toContain('Your plan allows 1 employees');
});

it('lists and shows imports for HR, and is forbidden to managers', function (): void {
    $id = $this->actingAsTenantUser($this->hr)
        ->post($this->tenantUrl($this->tenant, 'employees/import'), ['file' => csvUpload("employee_code,first_name,last_name,hire_date,department_code\n")])
        ->json('data.id');

    $this->getJson($this->tenantUrl($this->tenant, 'employee-imports'))->assertOk()->assertJsonPath('data.0.id', $id);
    $this->getJson($this->tenantUrl($this->tenant, "employee-imports/{$id}"))->assertOk()->assertJsonPath('data.total_rows', 0);

    $manager = $this->createTenantUser($this->tenant, RoleName::DepartmentManager);
    $this->actingAsTenantUser($manager)->getJson($this->tenantUrl($this->tenant, 'employee-imports'))->assertForbidden();
});

it('rejects non-CSV uploads', function (): void {
    $this->actingAsTenantUser($this->hr)
        ->post($this->tenantUrl($this->tenant, 'employees/import'), ['file' => UploadedFile::fake()->image('photo.png')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('file');
});
