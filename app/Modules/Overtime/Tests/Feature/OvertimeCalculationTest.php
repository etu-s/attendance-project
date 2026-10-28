<?php

declare(strict_types=1);

use App\Modules\Attendance\Models\PunchCorrection;
use App\Modules\Attendance\Models\RawPunch;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Services\SettingsService;
use App\Modules\Leave\Tests\Support\LeaveTesting;
use App\Modules\Organization\Models\Employee;
use App\Modules\Overtime\Enums\OvertimeStatus;
use App\Modules\Overtime\Models\OvertimeRecord;
use App\Modules\Overtime\Services\OvertimeCalculator;
use App\Modules\Scheduling\Models\Holiday;
use App\Modules\Scheduling\Models\ScheduleAssignment;
use App\Modules\Timetable\Models\Shift;
use App\Modules\Timetable\Models\Timetable;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class, LeaveTesting::class);

/*
 * Office Mon–Fri 09:00–17:00 UTC, unpaid lunch 13:00–14:00 (420 scheduled).
 * Defaults: at least 30 minutes, rounded down to 15, rates 150/200/200.
 * Now is Wed 2026-03-04.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-04 12:00:00', 'UTC'));
    $this->leaveWorkspace();
});

function overtimeOf(object $test, string $date, ?Employee $employee = null): ?OvertimeRecord
{
    return $test->tenant->run(fn () => OvertimeRecord::query()
        ->where('employee_id', ($employee ?? $test->employee)->id)
        ->where('work_date', $date)
        ->first());
}

it('counts time after the shift, rounded down, at the working-day rate', function (): void {
    $this->punch('E-1', '2026-03-02 08:55', '2026-03-02 18:10');

    expect(overtimeOf($this, '2026-03-02'))
        ->after_minutes->toBe(60)
        ->calculated_minutes->toBe(60)
        ->weighted_minutes->toBe(90)
        ->status->toBe(OvertimeStatus::Pending);
});

it('makes up a late start before counting overtime', function (): void {
    // In 09:30, out 17:40: 40 minutes after the end, but only 10 more than scheduled.
    $this->punch('E-1', '2026-03-02 09:30', '2026-03-02 17:40');

    expect(overtimeOf($this, '2026-03-02'))->toBeNull();
});

it('counts early arrival only when the setting is on', function (): void {
    $this->punch('E-1', '2026-03-02 07:30', '2026-03-02 17:00');
    expect(overtimeOf($this, '2026-03-02'))->toBeNull();

    $this->tenant->run(function (): void {
        app(SettingsService::class)->update(['overtime.count_early_arrival' => true]);
        app(OvertimeCalculator::class)->recalculate(null, LocalDate::parse('2026-03-02'), LocalDate::parse('2026-03-02'));
    });

    expect(overtimeOf($this, '2026-03-02'))->before_minutes->toBe(90)->after_minutes->toBe(0);
});

it('applies the daily cap', function (): void {
    $this->tenant->run(fn () => app(SettingsService::class)->update(['overtime.daily_cap_minutes' => 45]));
    $this->punch('E-1', '2026-03-02 08:55', '2026-03-02 18:10');

    expect(overtimeOf($this, '2026-03-02'))->calculated_minutes->toBe(45);
});

it('counts all work on rest days and holidays at their rates', function (): void {
    $this->tenant->run(fn () => Holiday::factory()->create(['date' => '2026-03-02', 'name' => 'Holiday']));
    $this->punch('E-1', '2026-02-28 10:00', '2026-02-28 14:00', '2026-03-02 09:00', '2026-03-02 17:30');

    expect(overtimeOf($this, '2026-02-28'))->rest_day_minutes->toBe(240)->weighted_minutes->toBe(480)
        ->and(overtimeOf($this, '2026-03-02'))->holiday_minutes->toBe(450)->weighted_minutes->toBe(900);
});

it('measures overnight shifts on their work date', function (): void {
    $night = $this->tenant->run(function (): Employee {
        $timetable = Timetable::factory()->between('22:00', '06:00')->create();
        $shift = Shift::factory()->on($timetable, [0, 1, 2, 3, 4])->create();
        $employee = Employee::factory()->inDepartment($this->department)->create(['employee_code' => 'N-1', 'hire_date' => '2026-01-01']);
        ScheduleAssignment::factory()->forEmployee($employee)->create(['shift_id' => $shift->id, 'effective_from' => '2026-01-01', 'anchor_date' => '2026-01-05']);

        return $employee;
    });

    $this->punch('N-1', '2026-03-02 21:58', '2026-03-03 07:05');

    expect(overtimeOf($this, '2026-03-02', $night))->after_minutes->toBe(60);
});

it('halves the expected time on a half day of leave and ignores full leave days', function (): void {
    $this->approveLeave('2026-03-02', '2026-03-02', ['half_end' => true]);
    $this->approveLeave('2026-03-03', '2026-03-03');
    // Afternoon only (210 expected) until 18:00: 240 worked, 30 more than expected.
    $this->punch('E-1', '2026-03-02 13:00', '2026-03-02 18:00', '2026-03-03 09:00', '2026-03-03 19:00');

    expect(overtimeOf($this, '2026-03-02'))->calculated_minutes->toBe(30)
        ->and(overtimeOf($this, '2026-03-03'))->toBeNull();
});

it('reopens a decided record when its calculation changes, and drops undecided ones', function (): void {
    $this->punch('E-1', '2026-03-02 08:55', '2026-03-02 18:10', '2026-03-03 09:00', '2026-03-03 18:00');
    $this->tenant->run(fn () => overtimeOf($this, '2026-03-02')->update(['status' => OvertimeStatus::Approved, 'approved_minutes' => 60]));

    // A later punch extends Monday; a correction removes Tuesday's late out.
    $this->punch('E-1', '2026-03-02 19:00');
    $tuesdayOut = $this->tenant->run(fn () => RawPunch::query()->where('punched_at', '2026-03-03 18:00:00')->value('id'));
    $this->tenant->run(fn () => PunchCorrection::factory()->approved()->create([
        'employee_id' => $this->employee->id, 'work_date' => '2026-03-03', 'kind' => 'ignore', 'punched_at' => null, 'raw_punch_id' => $tuesdayOut,
    ]));
    $this->recalculate('2026-03-03', '2026-03-03');

    expect(overtimeOf($this, '2026-03-02'))
        ->status->toBe(OvertimeStatus::Pending)
        ->changed_after_decision->toBeTrue()
        ->calculated_minutes->toBe(120)
        ->approved_minutes->toBeNull()
        ->and(overtimeOf($this, '2026-03-03'))->toBeNull();
});
