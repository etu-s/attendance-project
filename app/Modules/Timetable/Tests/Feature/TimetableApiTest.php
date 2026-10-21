<?php

declare(strict_types=1);

use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Services\SettingsService;
use App\Modules\Timetable\Models\Shift;
use App\Modules\Timetable\Models\Timetable;
use App\Modules\Timetable\Services\UsageRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = $this->createTenant('acme');
    $this->hr = $this->createTenantUser($this->tenant, RoleName::HrManager);
    $this->actingAsTenantUser($this->hr);
});

function fixedTimetable(array $overrides = []): array
{
    return array_merge([
        'name' => 'Day',
        'code' => 'DAY',
        'type' => 'fixed',
        'start_minute' => 540,
        'duration_minutes' => 540,
        'breaks' => [
            ['kind' => 'fixed', 'start_offset' => 180, 'duration_minutes' => 60, 'is_paid' => false],
            ['kind' => 'floating', 'start_offset' => null, 'duration_minutes' => 15, 'is_paid' => true],
        ],
    ], $overrides);
}

describe('create and validate', function (): void {
    it('creates a fixed timetable with breaks and reports derived values', function (): void {
        $this->postJson($this->tenantUrl($this->tenant, 'timetables'), fixedTimetable())
            ->assertCreated()
            ->assertJsonPath('data.end_minute', 1080)
            ->assertJsonPath('data.crosses_midnight', false)
            ->assertJsonPath('data.work_minutes', 480) // 9 h minus the unpaid hour
            ->assertJsonPath('data.breaks.0.start_offset', 180)
            ->assertJsonPath('data.breaks.1.kind', 'floating')
            ->assertJsonPath('meta.locked', false)
            ->assertJsonPath('meta.effective.late_grace_minutes', 5)
            ->assertJsonPath('meta.effective.check_out_after_minutes', 240);
    });

    it('creates an overnight timetable', function (): void {
        $this->postJson($this->tenantUrl($this->tenant, 'timetables'), fixedTimetable([
            'code' => 'NIGHT', 'start_minute' => 1320, 'duration_minutes' => 480, 'breaks' => [],
        ]))->assertCreated()->assertJsonPath('data.end_minute', 1800)->assertJsonPath('data.crosses_midnight', true);
    });

    it('creates a flexible timetable with core hours and clears flexible fields on fixed ones', function (): void {
        $this->postJson($this->tenantUrl($this->tenant, 'timetables'), [
            'name' => 'Flex', 'code' => 'FLEX', 'type' => 'flexible',
            'start_minute' => 420, 'duration_minutes' => 780, 'required_minutes' => 480,
            'core_start_offset' => 180, 'core_duration_minutes' => 300,
            'breaks' => [['kind' => 'floating', 'duration_minutes' => 30]],
        ])->assertCreated()->assertJsonPath('data.work_minutes', 480)->assertJsonPath('data.core_duration_minutes', 300);

        $this->postJson($this->tenantUrl($this->tenant, 'timetables'), fixedTimetable(['required_minutes' => 300, 'breaks' => []]))
            ->assertCreated()->assertJsonPath('data.required_minutes', null);
    });

    it('rejects inconsistent rules field by field', function (array $payload, string $field): void {
        $this->postJson($this->tenantUrl($this->tenant, 'timetables'), $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    })->with([
        'flexible without required time' => [fixedTimetable(['type' => 'flexible', 'breaks' => []]), 'required_minutes'],
        'required longer than window' => [fixedTimetable(['type' => 'flexible', 'required_minutes' => 600, 'breaks' => []]), 'required_minutes'],
        'core hours outside window' => [fixedTimetable(['type' => 'flexible', 'required_minutes' => 300, 'core_start_offset' => 400, 'core_duration_minutes' => 200, 'breaks' => []]), 'core_duration_minutes'],
        'core start without end' => [fixedTimetable(['type' => 'flexible', 'required_minutes' => 300, 'core_start_offset' => 60, 'breaks' => []]), 'core_duration_minutes'],
        'fixed break outside' => [fixedTimetable(['breaks' => [['kind' => 'fixed', 'start_offset' => 520, 'duration_minutes' => 60]]]), 'breaks.0.start_offset'],
        'fixed break without time' => [fixedTimetable(['breaks' => [['kind' => 'fixed', 'duration_minutes' => 60]]]), 'breaks.0.start_offset'],
        'overlapping breaks' => [fixedTimetable(['breaks' => [['kind' => 'fixed', 'start_offset' => 100, 'duration_minutes' => 60], ['kind' => 'fixed', 'start_offset' => 130, 'duration_minutes' => 30]]]), 'breaks.1.start_offset'],
        'fixed break on flexible' => [fixedTimetable(['type' => 'flexible', 'required_minutes' => 300]), 'breaks.0.kind'],
        'breaks plus required too long' => [fixedTimetable(['type' => 'flexible', 'required_minutes' => 520, 'breaks' => [['kind' => 'floating', 'duration_minutes' => 60]]]), 'breaks'],
        'start out of day' => [fixedTimetable(['start_minute' => 1440]), 'start_minute'],
        'too long' => [fixedTimetable(['duration_minutes' => 1500]), 'duration_minutes'],
        'grace longer than timetable' => [fixedTimetable(['duration_minutes' => 60, 'late_grace_minutes' => 90, 'breaks' => []]), 'late_grace_minutes'],
        'bad colour' => [fixedTimetable(['color' => 'red']), 'color'],
        'too many breaks' => [fixedTimetable(['breaks' => array_fill(0, 5, ['kind' => 'floating', 'duration_minutes' => 5])]), 'breaks'],
    ]);

    it('rejects duplicate codes case-insensitively', function (): void {
        $this->tenant->run(fn () => Timetable::factory()->create(['code' => 'day']));

        $this->postJson($this->tenantUrl($this->tenant, 'timetables'), fixedTimetable())
            ->assertUnprocessable()->assertJsonValidationErrors('code');
    });
});

describe('defaults and overrides', function (): void {
    it('falls back to workspace defaults and follows their changes', function (): void {
        $id = $this->postJson($this->tenantUrl($this->tenant, 'timetables'), fixedTimetable(['early_leave_grace_minutes' => 0]))->json('data.id');
        $this->tenant->run(fn () => app(SettingsService::class)->update(['attendance.late_grace_minutes' => 12]));

        $this->getJson($this->tenantUrl($this->tenant, "timetables/{$id}"))
            ->assertJsonPath('data.late_grace_minutes', null)
            ->assertJsonPath('meta.effective.late_grace_minutes', 12)
            ->assertJsonPath('meta.effective.early_leave_grace_minutes', 0);
    });

    it('registers the attendance defaults as workspace settings', function (): void {
        $this->actingAsTenantUser($this->tenantAdmin($this->tenant))
            ->getJson($this->tenantUrl($this->tenant, 'settings'))
            ->assertJsonFragment(['key' => 'attendance.check_in_before_minutes', 'value' => 120, 'group' => 'attendance']);
    });
});

describe('update, lock, duplicate and delete', function (): void {
    beforeEach(function (): void {
        $this->id = $this->postJson($this->tenantUrl($this->tenant, 'timetables'), fixedTimetable())->json('data.id');
    });

    it('updates partially and audits break changes', function (): void {
        $this->putJson($this->tenantUrl($this->tenant, "timetables/{$this->id}"), ['name' => 'Office day', 'breaks' => []])
            ->assertOk()->assertJsonPath('data.name', 'Office day')->assertJsonCount(0, 'data.breaks');

        $this->tenant->run(function (): void {
            $log = AuditLog::query()->where('auditable_type', 'timetable')->where('auditable_id', (string) $this->id)
                ->latest('id')->firstOrFail();
            expect($log->after)->toBe(['breaks' => []])->and($log->before['breaks'])->toHaveCount(2);
        });
    });

    it('locks time rules once a schedule uses the timetable, but not its name', function (): void {
        $this->tenant->run(function (): void {
            Shift::factory()->on(Timetable::query()->findOrFail($this->id), [0])->create();
            app(UsageRegistry::class)->whenShiftInUse(fn (int $shiftId) => true);
        });

        $this->getJson($this->tenantUrl($this->tenant, "timetables/{$this->id}"))->assertJsonPath('meta.locked', true);

        $this->putJson($this->tenantUrl($this->tenant, "timetables/{$this->id}"), ['start_minute' => 600])
            ->assertStatus(409)->assertJsonPath('rule', 'timetable_locked');
        $this->putJson($this->tenantUrl($this->tenant, "timetables/{$this->id}"), ['breaks' => []])
            ->assertStatus(409);
        $this->putJson($this->tenantUrl($this->tenant, "timetables/{$this->id}"), ['name' => 'Renamed', 'color' => '#22aa55', 'start_minute' => 540])
            ->assertOk()->assertJsonPath('data.name', 'Renamed');
    });

    it('duplicates with a free code so a locked timetable can be replaced', function (): void {
        $this->postJson($this->tenantUrl($this->tenant, "timetables/{$this->id}/duplicate"))
            ->assertCreated()
            ->assertJsonPath('data.code', 'DAY-2')
            ->assertJsonPath('data.name', 'Day (copy)')
            ->assertJsonCount(2, 'data.breaks');

        $this->postJson($this->tenantUrl($this->tenant, "timetables/{$this->id}/duplicate"))->assertJsonPath('data.code', 'DAY-3');
    });

    it('refuses to delete a timetable that is part of a shift', function (): void {
        $this->tenant->run(fn () => Shift::factory()->on(Timetable::query()->findOrFail($this->id), [2])->create());

        $this->deleteJson($this->tenantUrl($this->tenant, "timetables/{$this->id}"))
            ->assertStatus(409)->assertJsonPath('rule', 'timetable_in_use');
    });

    it('deletes an unused timetable', function (): void {
        $this->deleteJson($this->tenantUrl($this->tenant, "timetables/{$this->id}"))->assertNoContent();
    });
});

describe('listing and access', function (): void {
    it('lists with search, type filter, sort by start and shift counts', function (): void {
        $this->tenant->run(function (): void {
            $night = Timetable::factory()->between('22:00', '06:00')->create(['name' => 'Night']);
            Timetable::factory()->between('06:00', '14:00')->create(['name' => 'Early']);
            Timetable::factory()->flexible('07:00', '20:00', 480)->create(['name' => 'Flex']);
            Shift::factory()->on($night, [0, 1])->create();
            Shift::factory()->on($night, [3])->create();
        });

        $this->getJson($this->tenantUrl($this->tenant, 'timetables?sort=start_minute&type=fixed'))
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.name', 'Early')
            ->assertJsonPath('data.1.shifts_count', 2);

        $this->getJson($this->tenantUrl($this->tenant, 'timetables?search=fle'))->assertJsonPath('meta.total', 1);
    });

    it('lets department managers view but not manage, and hides timetables from employees', function (): void {
        $manager = $this->createTenantUser($this->tenant, RoleName::DepartmentManager);
        $employee = $this->createTenantUser($this->tenant, RoleName::Employee);

        $this->actingAsTenantUser($manager)->getJson($this->tenantUrl($this->tenant, 'timetables'))->assertOk();
        $this->actingAsTenantUser($manager)->postJson($this->tenantUrl($this->tenant, 'timetables'), fixedTimetable())->assertForbidden();
        $this->actingAsTenantUser($employee)->getJson($this->tenantUrl($this->tenant, 'timetables'))->assertForbidden();
    });
});
