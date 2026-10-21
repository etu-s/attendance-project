<?php

declare(strict_types=1);

use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Services\SettingsService;
use App\Modules\Timetable\Models\Shift;
use App\Modules\Timetable\Models\Timetable;
use App\Modules\Timetable\Services\UsageRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = $this->createTenant('acme');
    $this->hr = $this->createTenantUser($this->tenant, RoleName::HrManager);
    $this->actingAsTenantUser($this->hr);

    [$this->day, $this->night, $this->early, $this->late, $this->evening] = $this->tenant->run(fn () => [
        Timetable::factory()->between('09:00', '17:00')->create(['name' => 'Day']),
        Timetable::factory()->between('22:00', '06:00')->create(['name' => 'Night']),
        Timetable::factory()->between('05:00', '13:00')->create(['name' => 'Early']),
        Timetable::factory()->between('13:00', '15:00')->create(['name' => 'Late']),
        Timetable::factory()->between('18:00', '20:00')->create(['name' => 'Evening']),
    ]);
});

function createShift(array $overrides = []): int
{
    return test()->postJson(test()->tenantUrl(test()->tenant, 'shifts'), array_merge([
        'name' => 'Office week', 'code' => 'OFFICE', 'cycle_unit' => 'week', 'cycle_length' => 1,
    ], $overrides))->assertCreated()->json('data.id');
}

function setDays(int $shiftId, array $days): TestResponse
{
    return test()->putJson(test()->tenantUrl(test()->tenant, "shifts/{$shiftId}/days"), ['days' => $days]);
}

it('creates a weekly shift and saves its cycle', function (): void {
    $id = createShift();

    setDays($id, array_map(fn ($i) => ['day_index' => $i, 'timetable_ids' => [$this->day->id]], range(0, 4)))
        ->assertOk()
        ->assertJsonPath('data.cycle_days', 7)
        ->assertJsonPath('data.working_days', 5)
        ->assertJsonPath('data.days.0.timetables.0.name', 'Day')
        ->assertJsonPath('meta.locked', false);

    $this->tenant->run(function () use ($id): void {
        $log = AuditLog::query()->where('auditable_type', 'shift')->where('auditable_id', (string) $id)->latest('id')->firstOrFail();
        expect($log->before)->toBe(['days' => []])->and($log->after['days'])->toHaveCount(5);
    });
});

it('saves split days of up to three timetables, ordered by start', function (): void {
    $id = createShift(['cycle_unit' => 'day', 'cycle_length' => 1]);

    setDays($id, [['day_index' => 0, 'timetable_ids' => [$this->evening->id, $this->day->id]]])
        ->assertOk()
        ->assertJsonPath('data.days.0.timetables.0.name', 'Day')
        ->assertJsonPath('data.days.0.timetables.1.name', 'Evening');
});

it('rejects invalid cycles', function (callable $days, string $error): void {
    $id = createShift();

    setDays($id, $days($this))->assertUnprocessable()->assertJsonValidationErrors($error);
})->with([
    'overnight spills into the next day' => [fn ($t) => [
        ['day_index' => 0, 'timetable_ids' => [$t->night->id]],
        ['day_index' => 1, 'timetable_ids' => [$t->early->id]],
    ], 'days.1'],
    'last day wraps into the first' => [fn ($t) => [
        ['day_index' => 6, 'timetable_ids' => [$t->night->id]],
        ['day_index' => 0, 'timetable_ids' => [$t->early->id]],
    ], 'days.0'],
    'overlap on the same day' => [fn ($t) => [['day_index' => 2, 'timetable_ids' => [$t->day->id, $t->late->id]]], 'days.2'],
    'more than three per day' => [fn ($t) => [['day_index' => 3, 'timetable_ids' => [$t->early->id, $t->late->id, $t->evening->id, $t->night->id]]], 'days.3'],
    'outside the cycle' => [fn ($t) => [['day_index' => 7, 'timetable_ids' => [$t->day->id]]], 'days.7'],
    'unknown timetable' => [fn ($t) => [['day_index' => 0, 'timetable_ids' => [99999]]], 'days.0'],
    'duplicate day' => [fn ($t) => [['day_index' => 1, 'timetable_ids' => [$t->day->id]], ['day_index' => 1, 'timetable_ids' => [$t->early->id]]], 'days.0.day_index'],
]);

it('refuses inactive timetables', function (): void {
    $this->tenant->run(fn () => $this->day->update(['is_active' => false]));

    setDays(createShift(), [['day_index' => 0, 'timetable_ids' => [$this->day->id]]])->assertUnprocessable();
});

it('validates cycle lengths per unit and refuses to cut off working days', function (): void {
    $this->postJson($this->tenantUrl($this->tenant, 'shifts'), ['name' => 'X', 'code' => 'X', 'cycle_unit' => 'week', 'cycle_length' => 9])
        ->assertUnprocessable()->assertJsonValidationErrors('cycle_length');

    $id = createShift(['cycle_unit' => 'day', 'cycle_length' => 8]);
    setDays($id, [['day_index' => 6, 'timetable_ids' => [$this->day->id]]])->assertOk();

    $this->putJson($this->tenantUrl($this->tenant, "shifts/{$id}"), ['cycle_length' => 4])
        ->assertUnprocessable()->assertJsonValidationErrors('cycle_length');
    $this->putJson($this->tenantUrl($this->tenant, "shifts/{$id}"), ['cycle_length' => 7])
        ->assertOk()->assertJsonPath('data.cycle_days', 7);
});

it('previews the cycle on real dates in the workspace timezone', function (): void {
    $this->tenant->run(fn () => app(SettingsService::class)->update(['general.timezone' => 'Asia/Karachi']));
    $id = createShift(['cycle_unit' => 'day', 'cycle_length' => 2]);
    setDays($id, [['day_index' => 0, 'timetable_ids' => [$this->night->id]]]);

    $this->getJson($this->tenantUrl($this->tenant, "shifts/{$id}/preview?anchor=2026-03-01&from=2026-03-01&to=2026-03-04"))
        ->assertOk()
        ->assertJsonPath('meta.timezone', 'Asia/Karachi')
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('data.0.day_index', 0)
        ->assertJsonPath('data.0.periods.0.starts_at', '2026-03-01T17:00:00Z')
        ->assertJsonPath('data.0.periods.0.ends_at', '2026-03-02T01:00:00Z')
        ->assertJsonPath('data.0.periods.0.minutes', 480)
        ->assertJsonCount(0, 'data.1.periods')
        ->assertJsonPath('data.2.periods.0.timetable.name', 'Night');

    $this->getJson($this->tenantUrl($this->tenant, "shifts/{$id}/preview?from=2026-01-01&to=2026-04-01"))
        ->assertUnprocessable()->assertJsonValidationErrors('to');
    $this->getJson($this->tenantUrl($this->tenant, "shifts/{$id}/preview?from=2026-01-01&to=2026-01-02&timezone=Mars/Base"))
        ->assertUnprocessable()->assertJsonValidationErrors('timezone');
});

it('locks the cycle of a shift in use and refuses to delete it', function (): void {
    $id = createShift();
    setDays($id, [['day_index' => 0, 'timetable_ids' => [$this->day->id]]]);
    $this->tenant->run(fn () => app(UsageRegistry::class)->whenShiftInUse(fn (int $shiftId) => $shiftId === $id));

    $this->getJson($this->tenantUrl($this->tenant, "shifts/{$id}"))->assertJsonPath('meta.locked', true);
    setDays($id, [])->assertStatus(409)->assertJsonPath('rule', 'shift_locked');
    $this->putJson($this->tenantUrl($this->tenant, "shifts/{$id}"), ['cycle_length' => 2])->assertStatus(409);
    $this->putJson($this->tenantUrl($this->tenant, "shifts/{$id}"), ['name' => 'Renamed'])->assertOk();
    $this->deleteJson($this->tenantUrl($this->tenant, "shifts/{$id}"))->assertStatus(409)->assertJsonPath('rule', 'shift_in_use');
});

it('duplicates a shift with its cycle and deletes unused shifts', function (): void {
    $id = createShift();
    setDays($id, [['day_index' => 0, 'timetable_ids' => [$this->day->id]], ['day_index' => 2, 'timetable_ids' => [$this->night->id]]]);

    $this->postJson($this->tenantUrl($this->tenant, "shifts/{$id}/duplicate"))
        ->assertCreated()->assertJsonPath('data.code', 'OFFICE-2')->assertJsonPath('data.working_days', 2);

    $this->deleteJson($this->tenantUrl($this->tenant, "shifts/{$id}"))->assertNoContent();
    $this->tenant->run(fn () => expect(Timetable::query()->count())->toBe(5));
});

it('lists shifts with their cycles without N+1 queries', function (): void {
    $this->tenant->run(fn () => Shift::factory()->count(3)->on($this->day, [0, 1])->create());

    $count = function (): int {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        $this->getJson($this->tenantUrl($this->tenant, 'shifts?per_page=100'))->assertOk();

        return $queries;
    };

    $this->getJson($this->tenantUrl($this->tenant, 'shifts'))->assertOk(); // warm caches
    $few = $count();
    $this->tenant->run(fn () => Shift::factory()->count(10)->on($this->night, [3])->create());
    $this->actingAsTenantUser($this->hr);

    expect($count())->toBe($few);
});

it('lets managers view but not manage shifts', function (): void {
    $manager = $this->createTenantUser($this->tenant, RoleName::DepartmentManager);
    $id = createShift();

    $this->actingAsTenantUser($manager)->getJson($this->tenantUrl($this->tenant, 'shifts'))->assertOk();
    $this->actingAsTenantUser($manager)->getJson($this->tenantUrl($this->tenant, "shifts/{$id}/preview?from=2026-01-01&to=2026-01-07"))->assertOk();
    $this->actingAsTenantUser($manager)->putJson($this->tenantUrl($this->tenant, "shifts/{$id}/days"), ['days' => []])->assertForbidden();
});
