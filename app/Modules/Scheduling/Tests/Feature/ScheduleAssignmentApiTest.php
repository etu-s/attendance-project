<?php

declare(strict_types=1);

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Scheduling\Events\ScheduleChanged;
use App\Modules\Scheduling\Models\ScheduleAssignment;
use App\Modules\Timetable\Models\Shift;
use App\Modules\Timetable\Models\Timetable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(LocalDate::parse('2026-03-01')->setTime(9, 0));
    $this->tenant = $this->createTenant('acme');
    $this->hr = $this->createTenantUser($this->tenant, RoleName::HrManager);

    [$this->a, $this->b, $this->c, $this->ops] = $this->tenant->run(fn () => [
        Shift::factory()->on(Timetable::factory()->create(), [0])->create(['name' => 'A']),
        Shift::factory()->on(Timetable::factory()->create(), [1])->create(['name' => 'B']),
        Shift::factory()->on(Timetable::factory()->create(), [2])->create(['name' => 'C']),
        Department::factory()->create(['name' => 'Operations']),
    ]);
    $this->actingAsTenantUser($this->hr);
});

function assignDept(array $payload): TestResponse
{
    return test()->postJson(test()->tenantUrl(test()->tenant, 'schedule-assignments'), array_merge([
        'target_type' => 'department', 'target_id' => test()->ops->id,
    ], $payload));
}

/**
 * @return list<string> "shift from..to" for the Operations department, oldest first
 */
function deptTimeline(): array
{
    return test()->tenant->run(fn () => ScheduleAssignment::query()->with('shift')
        ->where('target_type', 'department')->where('target_id', test()->ops->id)->orderBy('effective_from')->get()
        ->map(fn ($a) => $a->shift->name.' '.$a->effective_from->toDateString().'..'.($a->effective_to?->toDateString() ?? 'open'))
        ->all());
}

it('creates an assignment with the anchor defaulting to the start date', function (): void {
    assignDept(['shift_id' => $this->a->id, 'effective_from' => '2026-01-01'])
        ->assertCreated()
        ->assertJsonPath('data.target.name', 'Operations')
        ->assertJsonPath('data.shift.name', 'A')
        ->assertJsonPath('data.anchor_date', '2026-01-01')
        ->assertJsonPath('data.effective_to', null);

    $this->postJson($this->tenantUrl($this->tenant, 'schedule-assignments'), ['target_type' => 'organization', 'target_id' => null, 'shift_id' => $this->b->id, 'effective_from' => '2026-01-01'])
        ->assertCreated()->assertJsonPath('data.target.name', 'Organization default');
});

it('splits an existing period around a bounded change and fires ScheduleChanged', function (): void {
    assignDept(['shift_id' => $this->a->id, 'effective_from' => '2026-01-01']);
    Event::fake([ScheduleChanged::class]);

    assignDept(['shift_id' => $this->b->id, 'effective_from' => '2026-02-10', 'effective_to' => '2026-02-20'])->assertCreated();

    expect(deptTimeline())->toBe([
        'A 2026-01-01..2026-02-09',
        'B 2026-02-10..2026-02-20',
        'A 2026-02-21..open',
    ]);
    Event::assertDispatched(ScheduleChanged::class, fn (ScheduleChanged $e) => $e->scopeType === 'department'
        && $e->scopeId === $this->ops->id && $e->from === '2026-02-10' && $e->to === '2026-02-20');
});

it('lets an open-ended change run only until the next planned period', function (): void {
    assignDept(['shift_id' => $this->a->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-01-31']);
    assignDept(['shift_id' => $this->c->id, 'effective_from' => '2026-04-01']);

    // Starts in a gap: fills up to the next planned period.
    assignDept(['shift_id' => $this->b->id, 'effective_from' => '2026-03-01'])->assertCreated();
    // Starts inside a bounded period: takes over its remainder only.
    assignDept(['shift_id' => $this->c->id, 'effective_from' => '2026-01-20'])->assertCreated();

    expect(deptTimeline())->toBe([
        'A 2026-01-01..2026-01-19',
        'C 2026-01-20..2026-01-31',
        'B 2026-03-01..2026-03-31',
        'C 2026-04-01..open',
    ]);
});

it('overwrites periods fully covered by a bounded backdated change', function (): void {
    assignDept(['shift_id' => $this->a->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-01-10']);
    assignDept(['shift_id' => $this->b->id, 'effective_from' => '2026-01-11', 'effective_to' => '2026-01-20']);

    assignDept(['shift_id' => $this->c->id, 'effective_from' => '2026-01-05', 'effective_to' => '2026-01-25'])->assertCreated();

    expect(deptTimeline())->toBe(['A 2026-01-01..2026-01-04', 'C 2026-01-05..2026-01-25']);

    $this->tenant->run(fn () => expect(AuditLog::query()->where('auditable_type', 'schedule_assignment')->where('action', 'deleted')->count())->toBe(1));
});

it('validates targets, shifts and dates', function (array $payload, string $field): void {
    $this->tenant->run(fn () => $this->c->update(['is_active' => false]));
    if (($payload['shift_id'] ?? null) === 'inactive') {
        $payload['shift_id'] = $this->c->id;
    }

    $this->postJson($this->tenantUrl($this->tenant, 'schedule-assignments'), array_merge([
        'target_type' => 'department', 'target_id' => $this->ops->id, 'shift_id' => $this->a->id, 'effective_from' => '2026-01-01',
    ], $payload))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'unknown department' => [['target_id' => 9999], 'target_id'],
    'organization with target' => [['target_type' => 'organization', 'target_id' => 5], 'target_id'],
    'bad type' => [['target_type' => 'team'], 'target_type'],
    'inactive shift' => [['shift_id' => 'inactive'], 'shift_id'],
    'end before start' => [['effective_to' => '2025-12-01'], 'effective_to'],
    'bad date' => [['effective_from' => '01/01/2026'], 'effective_from'],
]);

it('updates shift, anchor and end, but not past the next period', function (): void {
    $id = assignDept(['shift_id' => $this->a->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-01-31'])->json('data.id');
    assignDept(['shift_id' => $this->b->id, 'effective_from' => '2026-02-01']);

    $this->putJson($this->tenantUrl($this->tenant, "schedule-assignments/{$id}"), ['shift_id' => $this->c->id, 'anchor_date' => '2026-01-05', 'effective_to' => '2026-01-20'])
        ->assertOk()->assertJsonPath('data.shift.name', 'C')->assertJsonPath('data.effective_to', '2026-01-20');

    $this->putJson($this->tenantUrl($this->tenant, "schedule-assignments/{$id}"), ['effective_to' => '2026-02-05'])
        ->assertUnprocessable()->assertJsonValidationErrors('effective_to');
    $this->putJson($this->tenantUrl($this->tenant, "schedule-assignments/{$id}"), ['effective_to' => null])
        ->assertUnprocessable()->assertJsonValidationErrors('effective_to');
});

it('deletes an assignment', function (): void {
    $id = assignDept(['shift_id' => $this->a->id, 'effective_from' => '2026-01-01'])->json('data.id');

    $this->deleteJson($this->tenantUrl($this->tenant, "schedule-assignments/{$id}"))->assertNoContent();
    expect(deptTimeline())->toBe([]);
});

it('locks a shift once a schedule using it has started, not before', function (): void {
    assignDept(['shift_id' => $this->a->id, 'effective_from' => '2026-04-01']);
    $this->getJson($this->tenantUrl($this->tenant, "shifts/{$this->a->id}"))->assertJsonPath('meta.locked', false);

    $this->travelTo(LocalDate::parse('2026-04-01')->setTime(9, 0));
    $this->getJson($this->tenantUrl($this->tenant, "shifts/{$this->a->id}"))->assertJsonPath('meta.locked', true);
    $this->putJson($this->tenantUrl($this->tenant, "shifts/{$this->a->id}/days"), ['days' => []])->assertStatus(409);
    $this->deleteJson($this->tenantUrl($this->tenant, "shifts/{$this->a->id}"))->assertStatus(409);

    $timetable = $this->tenant->run(fn () => $this->a->days()->first()->timetable_id);
    $this->putJson($this->tenantUrl($this->tenant, "timetables/{$timetable}"), ['start_minute' => 600])->assertStatus(409);
});

it('filters by target and active date and names each target', function (): void {
    assignDept(['shift_id' => $this->a->id, 'effective_from' => '2026-01-01', 'effective_to' => '2026-01-31']);
    assignDept(['shift_id' => $this->b->id, 'effective_from' => '2026-02-01']);

    $this->getJson($this->tenantUrl($this->tenant, "schedule-assignments?target_type=department&target_id={$this->ops->id}&active_on=2026-01-15"))
        ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.shift.name', 'A');

    $this->getJson($this->tenantUrl($this->tenant, 'schedule-assignments?sort=effective_from'))
        ->assertJsonPath('data.0.effective_from', '2026-01-01')
        ->assertJsonPath('data.1.target.name', 'Operations');
});

it('shows managers only their own people\'s personal schedules and lets them not manage', function (): void {
    $manager = $this->createTenantUser($this->tenant, RoleName::DepartmentManager);
    [$mine, $other] = $this->tenant->run(function () use ($manager): array {
        $managed = Department::factory()->create();
        DB::table('department_managers')->insert(['department_id' => $managed->id, 'user_id' => $manager->id]);

        return [
            Employee::factory()->inDepartment($managed)->create(['hire_date' => '2026-01-01']),
            Employee::factory()->inDepartment($this->ops)->create(['hire_date' => '2026-01-01']),
        ];
    });
    foreach ([$mine, $other] as $employee) {
        $this->postJson($this->tenantUrl($this->tenant, 'schedule-assignments'), ['target_type' => 'employee', 'target_id' => $employee->id, 'shift_id' => $this->a->id, 'effective_from' => '2026-01-01'])->assertCreated();
    }
    assignDept(['shift_id' => $this->b->id, 'effective_from' => '2026-01-01']);

    $rows = $this->actingAsTenantUser($manager)->getJson($this->tenantUrl($this->tenant, 'schedule-assignments'))->assertOk()->json('data');
    expect(collect($rows)->map(fn ($r) => $r['target']['type'].':'.$r['target']['id'])->sort()->values()->all())
        ->toBe(["department:{$this->ops->id}", "employee:{$mine->id}"]);

    $this->actingAsTenantUser($manager)->postJson($this->tenantUrl($this->tenant, 'schedule-assignments'), [
        'target_type' => 'organization', 'target_id' => null, 'shift_id' => $this->a->id, 'effective_from' => '2026-01-01',
    ])->assertForbidden();

    $employee = $this->createTenantUser($this->tenant, RoleName::Employee);
    $this->actingAsTenantUser($employee)->getJson($this->tenantUrl($this->tenant, 'schedule-assignments'))->assertForbidden();
});
