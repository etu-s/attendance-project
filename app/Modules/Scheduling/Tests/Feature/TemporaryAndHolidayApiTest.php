<?php

declare(strict_types=1);

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\Location;
use App\Modules\Scheduling\Events\ScheduleChanged;
use App\Modules\Timetable\Models\Shift;
use App\Modules\Timetable\Models\Timetable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(LocalDate::parse('2026-03-01')->setTime(9, 0));
    $this->tenant = $this->createTenant('acme');
    $this->hr = $this->createTenantUser($this->tenant, RoleName::HrManager);

    [$this->shift, $this->employee, $this->location] = $this->tenant->run(fn () => [
        Shift::factory()->on(Timetable::factory()->create(), [0, 1, 2, 3, 4])->create(['name' => 'Cover']),
        Employee::factory()->inDepartment(Department::factory()->create())->create(['hire_date' => '2026-01-01']),
        Location::factory()->create(['name' => 'Lahore']),
    ]);
    $this->actingAsTenantUser($this->hr);
});

describe('temporary schedules', function (): void {
    it('creates a shift override and a day off, and lists them', function (): void {
        Event::fake([ScheduleChanged::class]);

        $this->postJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/temporary-schedules"), [
            'date_from' => '2026-03-02', 'date_to' => '2026-03-06', 'shift_id' => $this->shift->id, 'reason' => 'Cover',
        ])->assertCreated()->assertJsonPath('data.day_off', false)->assertJsonPath('data.anchor_date', '2026-03-02');

        $this->postJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/temporary-schedules"), [
            'date_from' => '2026-03-09', 'date_to' => '2026-03-09', 'reason' => 'Swap',
        ])->assertCreated()->assertJsonPath('data.day_off', true)->assertJsonPath('data.shift', null);

        $this->getJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/temporary-schedules"))
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.date_from', '2026-03-09');

        Event::assertDispatched(ScheduleChanged::class, fn ($e) => $e->scopeType === 'employee' && $e->from === '2026-03-02' && $e->to === '2026-03-06');
    });

    it('refuses overlaps, inverted ranges and inactive shifts', function (): void {
        $url = $this->tenantUrl($this->tenant, "employees/{$this->employee->id}/temporary-schedules");
        $this->postJson($url, ['date_from' => '2026-03-02', 'date_to' => '2026-03-06'])->assertCreated();

        $this->postJson($url, ['date_from' => '2026-03-06', 'date_to' => '2026-03-08'])->assertUnprocessable()->assertJsonValidationErrors('date_from');
        $this->postJson($url, ['date_from' => '2026-03-10', 'date_to' => '2026-03-09'])->assertUnprocessable()->assertJsonValidationErrors('date_to');

        $this->tenant->run(fn () => $this->shift->update(['is_active' => false]));
        $this->postJson($url, ['date_from' => '2026-04-01', 'date_to' => '2026-04-01', 'shift_id' => $this->shift->id])
            ->assertUnprocessable()->assertJsonValidationErrors('shift_id');
    });

    it('updates and deletes an override', function (): void {
        $id = $this->postJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/temporary-schedules"), [
            'date_from' => '2026-03-02', 'date_to' => '2026-03-03',
        ])->json('data.id');

        $this->putJson($this->tenantUrl($this->tenant, "temporary-schedules/{$id}"), ['date_to' => '2026-03-05', 'shift_id' => $this->shift->id])
            ->assertOk()->assertJsonPath('data.date_to', '2026-03-05')->assertJsonPath('data.day_off', false);

        $this->deleteJson($this->tenantUrl($this->tenant, "temporary-schedules/{$id}"))->assertNoContent();
    });

    it('lists overrides across employees with search, filters and the employee name', function (): void {
        $other = $this->tenant->run(fn () => Employee::factory()->create(['first_name' => 'Zara', 'last_name' => 'Iqbal', 'employee_code' => 'Z-9', 'hire_date' => '2026-01-01']));
        $this->postJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/temporary-schedules"), [
            'date_from' => '2026-03-02', 'date_to' => '2026-03-06', 'shift_id' => $this->shift->id,
        ])->assertCreated();
        $this->postJson($this->tenantUrl($this->tenant, "employees/{$other->id}/temporary-schedules"), [
            'date_from' => '2026-03-10', 'date_to' => '2026-03-10',
        ])->assertCreated();

        $this->getJson($this->tenantUrl($this->tenant, 'temporary-schedules'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.employee.full_name', 'Zara Iqbal')
            ->assertJsonPath('meta.total', 2);

        $this->getJson($this->tenantUrl($this->tenant, 'temporary-schedules?search=z-9'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_id', $other->id);
        $this->getJson($this->tenantUrl($this->tenant, 'temporary-schedules?kind=shift'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_id', $this->employee->id);
        $this->getJson($this->tenantUrl($this->tenant, 'temporary-schedules?active_on=2026-03-04'))->assertJsonCount(1, 'data');
        $this->getJson($this->tenantUrl($this->tenant, "temporary-schedules?employee_id={$other->id}"))->assertJsonCount(1, 'data');
        $this->getJson($this->tenantUrl($this->tenant, 'temporary-schedules?kind=other'))->assertUnprocessable()->assertJsonValidationErrors('kind');
    });

    it('lists only the overrides of employees a manager can see, and none to employees', function (): void {
        $manager = $this->createTenantUser($this->tenant, RoleName::DepartmentManager);
        $mine = $this->tenant->run(function () use ($manager): Employee {
            $managed = Department::factory()->create();
            DB::table('department_managers')->insert(['department_id' => $managed->id, 'user_id' => $manager->id]);

            return Employee::factory()->inDepartment($managed)->create(['hire_date' => '2026-01-01']);
        });
        foreach ([$mine, $this->employee] as $employee) {
            $this->postJson($this->tenantUrl($this->tenant, "employees/{$employee->id}/temporary-schedules"), [
                'date_from' => '2026-03-02', 'date_to' => '2026-03-02',
            ])->assertCreated();
        }

        $this->actingAsTenantUser($manager)->getJson($this->tenantUrl($this->tenant, 'temporary-schedules'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee_id', $mine->id);

        $employee = $this->createTenantUser($this->tenant, RoleName::Employee);
        $this->actingAsTenantUser($employee)->getJson($this->tenantUrl($this->tenant, 'temporary-schedules'))->assertForbidden();
    });

    it('is limited to employees the caller can see', function (): void {
        $manager = $this->createTenantUser($this->tenant, RoleName::DepartmentManager);

        $this->actingAsTenantUser($manager)
            ->getJson($this->tenantUrl($this->tenant, "employees/{$this->employee->id}/temporary-schedules"))
            ->assertForbidden();
    });
});

describe('holidays', function (): void {
    it('creates holidays and refuses a duplicate for the same scope', function (): void {
        $url = $this->tenantUrl($this->tenant, 'holidays');

        $this->postJson($url, ['date' => '2026-03-23', 'name' => 'Pakistan Day'])->assertCreated()->assertJsonPath('data.location', null);
        $this->postJson($url, ['date' => '2026-03-23', 'name' => 'Local', 'location_id' => $this->location->id])->assertCreated();
        $this->postJson($url, ['date' => '2026-03-23', 'name' => 'Again'])->assertUnprocessable()->assertJsonValidationErrors('date');
        $this->postJson($url, ['date' => '2026-03-24', 'name' => 'X', 'location_id' => 999])->assertUnprocessable()->assertJsonValidationErrors('location_id');
    });

    it('lists a year including recurring holidays from earlier years', function (): void {
        $url = $this->tenantUrl($this->tenant, 'holidays');
        $this->postJson($url, ['date' => '2024-12-25', 'name' => 'Christmas', 'recurs_yearly' => true])->assertCreated();
        $this->postJson($url, ['date' => '2024-02-29', 'name' => 'Leap', 'recurs_yearly' => true])->assertCreated();
        $this->postJson($url, ['date' => '2026-03-23', 'name' => 'Pakistan Day'])->assertCreated();
        $this->postJson($url, ['date' => '2027-01-01', 'name' => 'Next year'])->assertCreated();

        $this->getJson($this->tenantUrl($this->tenant, 'holidays?year=2026'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Pakistan Day')
            ->assertJsonPath('data.1.occurs_on', '2026-12-25');

        $this->getJson($this->tenantUrl($this->tenant, 'holidays?year=2028'))->assertJsonPath('data.0.occurs_on', '2028-02-29');
        $this->getJson($this->tenantUrl($this->tenant, 'holidays'))->assertUnprocessable()->assertJsonValidationErrors('year');
    });

    it('updates and deletes holidays and fires ScheduleChanged for the old and new date', function (): void {
        $id = $this->postJson($this->tenantUrl($this->tenant, 'holidays'), ['date' => '2026-03-23', 'name' => 'Day'])->json('data.id');
        Event::fake([ScheduleChanged::class]);

        $this->putJson($this->tenantUrl($this->tenant, "holidays/{$id}"), ['date' => '2026-03-24', 'location_id' => $this->location->id])
            ->assertOk()->assertJsonPath('data.location.name', 'Lahore');

        Event::assertDispatched(ScheduleChanged::class, fn ($e) => $e->scopeType === 'organization' && $e->from === '2026-03-23');
        Event::assertDispatched(ScheduleChanged::class, fn ($e) => $e->scopeType === 'location' && $e->from === '2026-03-24');

        $this->deleteJson($this->tenantUrl($this->tenant, "holidays/{$id}"))->assertNoContent();
    });

    it('is visible to employees but managed by HR only', function (): void {
        $employee = $this->createTenantUser($this->tenant, RoleName::Employee);

        $this->actingAsTenantUser($employee)->getJson($this->tenantUrl($this->tenant, 'holidays?year=2026'))->assertOk();
        $this->actingAsTenantUser($employee)->postJson($this->tenantUrl($this->tenant, 'holidays'), ['date' => '2026-05-01', 'name' => 'X'])->assertForbidden();
    });
});

it('writes nothing when a holiday or override request is refused', function (): void {
    $this->postJson($this->tenantUrl($this->tenant, 'holidays'), ['date' => 'nope', 'name' => 'X'])->assertUnprocessable();

    $this->tenant->run(fn () => expect(DB::table('holidays')->count())->toBe(0)->and(DB::table('temporary_schedules')->count())->toBe(0));
});
