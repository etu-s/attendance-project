<?php

declare(strict_types=1);

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Enums\CorrectionKind;
use App\Modules\Attendance\Enums\PunchRole;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\PunchCorrection;
use App\Modules\Attendance\Models\RawPunch;
use App\Modules\Attendance\Services\AttendanceCalculator;
use App\Modules\Attendance\Tests\Support\AttendanceTesting;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Services\SettingsService;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\Location;
use App\Modules\Scheduling\Enums\ScheduleTarget;
use App\Modules\Scheduling\Models\Holiday;
use App\Modules\Scheduling\Models\ScheduleAssignment;
use App\Modules\Scheduling\Services\ScheduleAssignmentService;
use App\Modules\Timetable\Models\Shift;
use App\Modules\Timetable\Models\Timetable;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class, AttendanceTesting::class);

/*
 * Office: Mon–Fri 09:00–17:00 UTC, unpaid lunch 13:00–14:00, grace 5 min,
 * punches accepted 07:00–21:00. "Now" is Wed 2026-03-04 12:00 UTC.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-04 12:00:00', 'UTC'));
    $this->attendanceWorkspace();
});

function attendanceSettings(object $test, array $values): void
{
    $test->tenant->run(fn () => app(SettingsService::class)->update($values));
}

describe('fixed timetables', function (): void {
    it('marks an on-time day present and deducts the unpaid lunch', function (): void {
        $this->punch('E-1', '2026-03-02 08:55', '2026-03-02 17:03');

        $day = $this->day('2026-03-02');
        expect($day->status)->toBe(AttendanceStatus::Present)
            ->and($day->worked_minutes)->toBe(488 - 60)
            ->and($day->scheduled_minutes)->toBe(420)
            ->and($day->late_minutes)->toBe(0)
            ->and($day->periods)->toHaveCount(1)
            ->and($day->periods[0]->break_minutes)->toBe(60)
            ->and($day->first_in_at->format('H:i'))->toBe('08:55')
            ->and($day->punches->pluck('role')->all())->toBe([PunchRole::In, PunchRole::Out]);
    });

    it('counts late minutes from the scheduled start once the grace is exceeded', function (): void {
        $this->punch('E-1', '2026-03-02 09:12', '2026-03-02 17:00', '2026-03-03 09:05', '2026-03-03 17:00');

        expect($this->day('2026-03-02'))->status->toBe(AttendanceStatus::Late)->late_minutes->toBe(12)
            ->and($this->day('2026-03-03'))->status->toBe(AttendanceStatus::Present)->late_minutes->toBe(0);
    });

    it('records early leave, and late plus early', function (): void {
        $this->punch('E-1', '2026-03-02 08:59', '2026-03-02 16:30', '2026-03-03 09:20', '2026-03-03 16:00');

        expect($this->day('2026-03-02'))->status->toBe(AttendanceStatus::EarlyLeave)->early_leave_minutes->toBe(30)
            ->and($this->day('2026-03-03'))->status->toBe(AttendanceStatus::LateEarly)->late_minutes->toBe(20)->early_leave_minutes->toBe(60);
    });

    it('treats a lone punch as the in or the out, whichever edge is nearer', function (): void {
        $this->punch('E-1', '2026-03-02 08:58', '2026-03-03 16:59');

        expect($this->day('2026-03-02'))->status->toBe(AttendanceStatus::Incomplete)->flags->toBe(['missing_out'])
            ->and($this->day('2026-03-03'))->status->toBe(AttendanceStatus::Incomplete)->flags->toBe(['missing_in']);
    });

    it('is absent without punches once the window closed, and in progress while it is open', function (): void {
        $this->punch('E-1', '2026-03-04 08:50');
        $this->recalculate('2026-03-02', '2026-03-04');

        expect($this->day('2026-03-02')->status)->toBe(AttendanceStatus::Absent)
            ->and($this->day('2026-03-04'))->status->toBe(AttendanceStatus::InProgress)->first_in_at->format('H:i')->toBe('08:50')
            ->and($this->tenant->run(fn () => AttendanceDay::query()->where('work_date', '2026-03-05')->exists()))->toBeFalse();
    });

    it('counts a double tap once (debounce)', function (): void {
        $this->punch('E-1', '2026-03-02 08:55:00', '2026-03-02 08:55:30', '2026-03-02 17:00');

        $day = $this->day('2026-03-02');
        expect($day->status)->toBe(AttendanceStatus::Present)
            ->and($day->punches->pluck('role')->all())->toBe([PunchRole::In, PunchRole::Duplicate, PunchRole::Out]);
    });

    it('keeps punches outside every window as unmatched without counting them', function (): void {
        $this->punch('E-1', '2026-03-02 03:00', '2026-03-02 09:00', '2026-03-02 17:00');

        $day = $this->day('2026-03-02');
        expect($day->status)->toBe(AttendanceStatus::Present)
            ->and($day->unmatched_punches)->toBe(1)
            ->and($day->worked_minutes)->toBe(420)
            ->and($day->punches->first()->role)->toBe(PunchRole::Unmatched);
    });

    it('rounds worked time down when configured', function (): void {
        attendanceSettings($this, ['attendance.rounding_minutes' => 15]);
        $this->punch('E-1', '2026-03-02 08:55', '2026-03-02 17:07');

        expect($this->day('2026-03-02')->worked_minutes)->toBe(420);
    });

    it('uses device direction only when the setting is on', function (): void {
        $this->punch('E-1', '2026-03-02 08:55 in', '2026-03-02 16:00 out', '2026-03-02 17:10 in');
        expect($this->day('2026-03-02')->status)->toBe(AttendanceStatus::Present);

        attendanceSettings($this, ['attendance.use_punch_direction' => true]);
        $this->recalculate('2026-03-02', '2026-03-02');

        expect($this->day('2026-03-02'))->status->toBe(AttendanceStatus::EarlyLeave)->early_leave_minutes->toBe(60);
    });

    it('marks a day below the timetable minimum absent but keeps the minutes', function (): void {
        $this->tenant->run(fn () => $this->timetable->update(['min_work_minutes' => 240]));
        $this->punch('E-1', '2026-03-02 09:00', '2026-03-02 10:00');

        expect($this->day('2026-03-02'))->status->toBe(AttendanceStatus::Absent)
            ->worked_minutes->toBe(60)
            ->flags->toBe(['below_minimum']);
    });
});

describe('special days', function (): void {
    it('records work on a rest day as off-day work', function (): void {
        $this->punch('E-1', '2026-03-01 10:00', '2026-03-01 14:00');

        expect($this->day('2026-03-01'))->status->toBe(AttendanceStatus::Rest)
            ->worked_minutes->toBe(240)
            ->flags->toBe(['off_day_work']);
    });

    it('keeps holiday work as worked minutes without late or early', function (): void {
        $this->tenant->run(fn () => Holiday::factory()->create(['date' => '2026-03-02', 'name' => 'Founders day']));
        $this->punch('E-1', '2026-03-02 09:30', '2026-03-02 16:00');

        expect($this->day('2026-03-02'))->status->toBe(AttendanceStatus::Holiday)
            ->holiday_name->toBe('Founders day')
            ->worked_minutes->toBe(330)
            ->late_minutes->toBe(0)
            ->early_leave_minutes->toBe(0)
            ->scheduled_minutes->toBe(0);
    });

    it('puts the morning half of an overnight shift on the previous work date', function (): void {
        $night = $this->tenant->run(function (): Employee {
            $timetable = Timetable::factory()->between('22:00', '06:00')->create();
            $shift = Shift::factory()->on($timetable, [0, 1, 2, 3, 4])->create();
            $employee = Employee::factory()->inDepartment($this->department)->create(['employee_code' => 'N-1', 'hire_date' => '2026-01-01']);
            ScheduleAssignment::factory()->forEmployee($employee)->create(['shift_id' => $shift->id, 'effective_from' => '2026-01-01', 'anchor_date' => '2026-01-05']);

            return $employee;
        });

        $this->punch('N-1', '2026-03-02 21:55', '2026-03-03 06:02');

        $monday = $this->day('2026-03-02', $night);
        expect($monday)->status->toBe(AttendanceStatus::Present)->worked_minutes->toBe(487)
            ->and($this->day('2026-03-03', $night)->status)->toBe(AttendanceStatus::Absent);
    });

    it('measures a night across the DST change by real elapsed time', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'UTC'));
        $night = $this->tenant->run(function (): Employee {
            $ny = Location::factory()->create(['timezone' => 'America/New_York']);
            $timetable = Timetable::factory()->between('22:00', '06:00')->create();
            $shift = Shift::factory()->on($timetable, [5])->create();
            $employee = Employee::factory()->inDepartment($this->department, $ny)->create(['employee_code' => 'NY-1', 'hire_date' => '2026-01-01']);
            ScheduleAssignment::factory()->forEmployee($employee)->create(['shift_id' => $shift->id, 'effective_from' => '2026-01-01', 'anchor_date' => '2026-01-05']);

            return $employee;
        });

        // Sat 7 Mar 21:58 EST (02:58Z) to Sun 8 Mar 06:01 EDT (10:01Z): clocks jumped forward at 02:00.
        $this->punch('NY-1', '2026-03-08 02:58', '2026-03-08 10:01');

        expect($this->day('2026-03-07', $night))->status->toBe(AttendanceStatus::Present)
            ->timezone->toBe('America/New_York')
            ->scheduled_minutes->toBe(420)
            ->worked_minutes->toBe(423);
    });

    it('places split-shift punches on the nearest period edge', function (): void {
        $split = $this->tenant->run(function (): Employee {
            $morning = Timetable::factory()->between('08:00', '12:00')->create();
            $afternoon = Timetable::factory()->between('14:00', '18:00')->create();
            $shift = Shift::factory()->on($morning, [0])->on($afternoon, [0])->create();
            $employee = Employee::factory()->inDepartment($this->department)->create(['employee_code' => 'S-1', 'hire_date' => '2026-01-01']);
            ScheduleAssignment::factory()->forEmployee($employee)->create(['shift_id' => $shift->id, 'effective_from' => '2026-01-01', 'anchor_date' => '2026-01-05']);

            return $employee;
        });

        $this->punch('S-1', '2026-03-02 07:58', '2026-03-02 12:01', '2026-03-02 13:59', '2026-03-02 18:02');

        $day = $this->day('2026-03-02', $split);
        expect($day->status)->toBe(AttendanceStatus::Present)
            ->and($day->periods)->toHaveCount(2)
            ->and($day->periods[0]->out_at->format('H:i'))->toBe('12:01')
            ->and($day->periods[1]->in_at->format('H:i'))->toBe('13:59')
            ->and($day->worked_minutes)->toBe(243 + 243);
    });

    it('treats a flexible timetable by core hours and required time', function (): void {
        $flex = $this->tenant->run(function (): Employee {
            $timetable = Timetable::factory()->flexible('07:00', '19:00', 480)->create(['core_start_offset' => 180, 'core_duration_minutes' => 300]);
            $shift = Shift::factory()->on($timetable, [0, 1])->create();
            $employee = Employee::factory()->inDepartment($this->department)->create(['employee_code' => 'F-1', 'hire_date' => '2026-01-01']);
            ScheduleAssignment::factory()->forEmployee($employee)->create(['shift_id' => $shift->id, 'effective_from' => '2026-01-01', 'anchor_date' => '2026-01-05']);

            return $employee;
        });

        $this->punch('F-1', '2026-03-02 10:20', '2026-03-02 19:00', '2026-03-03 08:00', '2026-03-03 15:30');

        expect($this->day('2026-03-02', $flex))->status->toBe(AttendanceStatus::Late)->late_minutes->toBe(20)->worked_minutes->toBe(520)
            ->and($this->day('2026-03-03', $flex))->status->toBe(AttendanceStatus::EarlyLeave)->early_leave_minutes->toBe(30);
    });

    it('shows days after termination as not employed', function (): void {
        $this->tenant->run(fn () => $this->employee->update(['status' => 'terminated', 'termination_date' => '2026-03-02']));
        $this->punch('E-1', '2026-03-03 09:00');

        expect($this->day('2026-03-03'))->status->toBe(AttendanceStatus::NotEmployed)->unmatched_punches->toBe(1);
    });
});

describe('recalculation', function (): void {
    it('gives the same rows when run again and does not rewrite unchanged days', function (): void {
        $this->punch('E-1', '2026-03-02 08:55', '2026-03-02 17:03');
        $before = $this->day('2026-03-02');

        $this->travel(10)->minutes();
        expect($this->recalculate('2026-03-01', '2026-03-03'))->toBe(0);

        $after = $this->day('2026-03-02');
        expect($after->id)->toBe($before->id)
            ->and($after->fingerprint)->toBe($before->fingerprint)
            ->and($after->calculated_at->equalTo($before->calculated_at))->toBeTrue();
    });

    it('recalculates when a schedule changes for days in the past', function (): void {
        $this->punch('E-1', '2026-03-02 08:55', '2026-03-02 17:03');
        expect($this->day('2026-03-02')->status)->toBe(AttendanceStatus::Present);

        $this->tenant->run(function (): void {
            $late = Timetable::factory()->between('13:00', '21:00')->create();
            $shift = Shift::factory()->on($late, [0, 1, 2, 3, 4])->create();
            app(ScheduleAssignmentService::class)->assign(ScheduleTarget::Employee, $this->employee->id, $shift->id, LocalDate::parse('2026-03-02'), LocalDate::parse('2026-03-02'), null);
        });

        // 08:55 is now outside the 11:00–01:00 window; 17:03 is nearer the 21:00 end.
        expect($this->day('2026-03-02'))->status->toBe(AttendanceStatus::Incomplete)
            ->unmatched_punches->toBe(1)
            ->flags->toBe(['missing_in']);
    });

    it('applies approved corrections and leaves raw punches untouched', function (): void {
        $this->punch('E-1', '2026-03-02 08:55', '2026-03-02 12:00', '2026-03-03 09:00');
        $wrong = $this->tenant->run(fn () => RawPunch::query()->where('punched_at', '2026-03-02 12:00:00')->value('id'));

        $this->tenant->run(function () use ($wrong): void {
            PunchCorrection::factory()->approved()->create(['employee_id' => $this->employee->id, 'work_date' => '2026-03-02', 'kind' => CorrectionKind::Ignore, 'punched_at' => null, 'raw_punch_id' => $wrong]);
            PunchCorrection::factory()->approved()->create(['employee_id' => $this->employee->id, 'work_date' => '2026-03-02', 'punched_at' => '2026-03-02 17:00:00']);
            PunchCorrection::factory()->create(['employee_id' => $this->employee->id, 'work_date' => '2026-03-03', 'punched_at' => '2026-03-03 17:00:00']);
        });
        $this->recalculate('2026-03-02', '2026-03-03');

        $day = $this->day('2026-03-02');
        expect($day->status)->toBe(AttendanceStatus::Present)
            ->and($day->punches->pluck('role')->all())->toBe([PunchRole::In, PunchRole::Ignored, PunchRole::Out])
            ->and($day->punches->last()->punch_correction_id)->not->toBeNull()
            ->and($this->day('2026-03-03')->status)->toBe(AttendanceStatus::Incomplete) // pending corrections do not count
            ->and($this->tenant->run(fn () => RawPunch::query()->count()))->toBe(3);
    });

    it('ignores punches older than the backlog window', function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-05-01 12:00:00', 'UTC'));
        $this->punch('E-1', '2026-03-02 09:00', '2026-03-02 17:00');

        expect($this->tenant->run(fn () => RawPunch::query()->where('too_old', true)->count()))->toBe(2);

        $this->recalculate('2026-03-02', '2026-03-02');
        expect($this->day('2026-03-02')->status)->toBe(AttendanceStatus::Absent);
    });

    it('keeps the query count flat as employees grow', function (): void {
        $count = function (int $add): int {
            return $this->tenant->run(function () use ($add): int {
                Employee::factory()->count($add)->inDepartment($this->department)->create(['hire_date' => '2026-01-01']);
                $calculator = app(AttendanceCalculator::class);
                $calculator->recalculate(null, LocalDate::parse('2026-03-02'), LocalDate::parse('2026-03-03'));

                DB::flushQueryLog();
                DB::enableQueryLog();
                $calculator->recalculate(null, LocalDate::parse('2026-03-02'), LocalDate::parse('2026-03-03'));
                $queries = count(DB::getQueryLog());
                DB::disableQueryLog();

                return $queries;
            });
        };

        // 4 employees, then 9: the second (unchanged) pass costs the same.
        expect($count(3))->toBe($count(5));
    });
});
