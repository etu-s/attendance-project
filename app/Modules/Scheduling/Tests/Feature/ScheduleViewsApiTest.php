<?php

declare(strict_types=1);

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Tenancy\TenantSqliteDatabaseManager;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Scheduling\Models\Holiday;
use App\Modules\Scheduling\Models\ScheduleAssignment;
use App\Modules\Timetable\Models\Shift;
use App\Modules\Timetable\Models\Timetable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(LocalDate::parse('2026-03-01')->setTime(9, 0));
    $this->tenant = $this->createTenant('acme');
    $this->hr = $this->createTenantUser($this->tenant, RoleName::HrManager);
    $this->manager = $this->createTenantUser($this->tenant, RoleName::DepartmentManager);
    $this->worker = $this->createTenantUser($this->tenant, RoleName::Employee);

    [$this->managed, $this->mine, $this->other, $this->me] = $this->tenant->run(function (): array {
        $shift = Shift::factory()->on(Timetable::factory()->between('09:00', '17:00')->create(['name' => 'Office']), [0, 1, 2, 3, 4])->create(['name' => 'Office week']);
        ScheduleAssignment::factory()->state(['shift_id' => $shift->id])->create();
        Holiday::factory()->create(['date' => '2026-03-04', 'name' => 'Founders day']);

        $managed = Department::factory()->create(['name' => 'Managed']);
        $other = Department::factory()->create(['name' => 'Other']);
        DB::table('department_managers')->insert(['department_id' => $managed->id, 'user_id' => $this->manager->id]);

        return [
            $managed,
            Employee::factory()->inDepartment($managed)->create(['hire_date' => '2026-01-01', 'last_name' => 'Alpha']),
            Employee::factory()->inDepartment($other)->create(['hire_date' => '2026-01-01', 'last_name' => 'Beta']),
            Employee::factory()->inDepartment($other)->create(['hire_date' => '2026-01-01', 'last_name' => 'Gamma', 'user_id' => $this->worker->id]),
        ];
    });
});

it('returns an employee\'s resolved schedule with sources and holidays', function (): void {
    $this->actingAsTenantUser($this->hr)
        ->getJson($this->tenantUrl($this->tenant, "employees/{$this->mine->id}/schedule?from=2026-03-02&to=2026-03-08"))
        ->assertOk()
        ->assertJsonCount(7, 'data')
        ->assertJsonPath('data.0.status', 'working')
        ->assertJsonPath('data.0.source.type', 'organization')
        ->assertJsonPath('data.0.periods.0.starts_at', '2026-03-02T09:00:00Z')
        ->assertJsonPath('data.0.periods.0.timetable.name', 'Office')
        ->assertJsonPath('data.2.status', 'holiday')
        ->assertJsonPath('data.2.holiday', 'Founders day')
        ->assertJsonPath('data.5.status', 'rest');
});

it('limits ranges and respects who may see the employee', function (): void {
    $this->actingAsTenantUser($this->hr)
        ->getJson($this->tenantUrl($this->tenant, "employees/{$this->mine->id}/schedule?from=2026-01-01&to=2026-04-01"))
        ->assertUnprocessable()->assertJsonValidationErrors('to');

    $this->actingAsTenantUser($this->manager)
        ->getJson($this->tenantUrl($this->tenant, "employees/{$this->mine->id}/schedule?from=2026-03-02&to=2026-03-02"))->assertOk();
    $this->actingAsTenantUser($this->manager)
        ->getJson($this->tenantUrl($this->tenant, "employees/{$this->other->id}/schedule?from=2026-03-02&to=2026-03-02"))->assertForbidden();
});

it('shows an employee their own schedule and 404s for unlinked logins', function (): void {
    $this->actingAsTenantUser($this->worker)
        ->getJson($this->tenantUrl($this->tenant, 'me/schedule?from=2026-03-02&to=2026-03-03'))
        ->assertOk()->assertJsonCount(2, 'data');

    $this->actingAsTenantUser($this->hr)->getJson($this->tenantUrl($this->tenant, 'me/schedule?from=2026-03-02&to=2026-03-03'))->assertNotFound();
});

it('builds a roster of visible employees with filters', function (): void {
    $this->actingAsTenantUser($this->hr)
        ->getJson($this->tenantUrl($this->tenant, 'roster?from=2026-03-02&days=7'))
        ->assertOk()
        ->assertJsonCount(7, 'dates')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.employee.name', $this->mine->fullName())
        ->assertJsonCount(7, 'data.0.days')
        ->assertJsonPath('data.0.days.2.status', 'holiday');

    $this->actingAsTenantUser($this->hr)
        ->getJson($this->tenantUrl($this->tenant, "roster?from=2026-03-02&department_id={$this->managed->id}"))
        ->assertJsonPath('meta.total', 1);

    $this->actingAsTenantUser($this->manager)
        ->getJson($this->tenantUrl($this->tenant, 'roster?from=2026-03-02'))
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.employee.employee_code', $this->mine->employee_code);

    $this->actingAsTenantUser($this->hr)->getJson($this->tenantUrl($this->tenant, 'roster?from=2026-03-02&days=40'))->assertUnprocessable();
    $this->actingAsTenantUser($this->worker)->getJson($this->tenantUrl($this->tenant, 'roster?from=2026-03-02'))->assertForbidden();
});

it('keeps tenant B\'s schedules, overrides and holidays out of tenant A', function (): void {
    $globex = $this->createTenant('globex');
    $globex->run(function (): void {
        $shift = Shift::factory()->on(Timetable::factory()->create(), [0])->create(['code' => 'GLX']);
        ScheduleAssignment::factory()->state(['shift_id' => $shift->id])->create();
        Holiday::factory()->create(['name' => 'Globex holiday']);
    });

    $pdo = new PDO('sqlite:'.app(TenantSqliteDatabaseManager::class)->path($this->tenant->database()->getName()));
    expect((int) $pdo->query("select count(*) from holidays where name like 'Globex%'")->fetchColumn())->toBe(0)
        ->and((int) $pdo->query('select count(*) from schedule_assignments')->fetchColumn())->toBe(1);
    $pdo = null;

    $this->actingAsTenantUser($this->hr)
        ->getJson($this->tenantUrl($this->tenant, 'holidays?year=2026'))
        ->assertJsonMissing(['name' => 'Globex holiday']);
});
