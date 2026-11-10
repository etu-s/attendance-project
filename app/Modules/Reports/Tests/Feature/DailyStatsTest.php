<?php

declare(strict_types=1);

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Services\AttendanceQuery;
use App\Modules\Attendance\Tests\Support\AttendanceTesting;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Reports\Models\AttendanceDailyStat;
use App\Modules\Reports\Services\DailyStatsBuilder;
use App\Modules\Reports\Services\StatsReader;
use App\Modules\Reports\Support\ReportParams;
use App\Modules\Scheduling\Models\ScheduleAssignment;
use App\Modules\Timetable\Models\Shift;
use App\Modules\Timetable\Models\Timetable;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class, AttendanceTesting::class);

/*
 * Office workspace (Monâ€“Fri 09:00â€“17:00, lunch 13:00â€“14:00), E-1 in one
 * department. 2026-03-02 is a Monday.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-06 12:00:00', 'UTC'));
    $this->attendanceWorkspace();
});

function reportsStatRow(string $date, ?int $departmentId = null): ?AttendanceDailyStat
{
    return AttendanceDailyStat::query()->where('work_date', $date)
        ->when($departmentId !== null, fn ($q) => $q->where('department_id', $departmentId))
        ->first();
}

it('keeps the rollup in step with punches processed by the attendance writer', function (): void {
    $this->punch('E-1', '2026-03-02 08:58', '2026-03-02 17:02', '2026-03-03 09:25', '2026-03-03 17:00', '2026-03-04 09:00');

    $this->tenant->run(function (): void {
        expect(reportsStatRow('2026-03-02'))
            ->days->toBe(1)->present->toBe(1)->late->toBe(0)->late_days->toBe(0)
            ->department_id->toBe($this->department->id)
            ->worked_minutes->toBe(AttendanceDay::query()->where('work_date', '2026-03-02')->value('worked_minutes'))
            ->and(reportsStatRow('2026-03-03'))->late->toBe(1)->late_days->toBe(1)->late_minutes->toBe(25)
            // A missing check-out: incomplete once the punch window has closed.
            ->and(reportsStatRow('2026-03-04'))->incomplete->toBe(1);
    });
});

it('rebuilds a whole date, so a day that moved department leaves no stale row', function (): void {
    $this->punch('E-1', '2026-03-02 09:00', '2026-03-02 17:00');
    $other = $this->tenant->run(fn () => Department::factory()->create());

    $this->tenant->run(function () use ($other): void {
        // As after a backdated transfer recalculated by Attendance.
        AttendanceDay::query()->where('work_date', '2026-03-02')->update(['department_id' => $other->id]);
        app(DailyStatsBuilder::class)->refresh(['2026-03-02']);

        expect(AttendanceDailyStat::query()->where('work_date', '2026-03-02')->count())->toBe(1)
            ->and(reportsStatRow('2026-03-02', $other->id))->present->toBe(1)
            ->and(reportsStatRow('2026-03-02', $this->department->id))->toBeNull();

        // Idempotent.
        app(DailyStatsBuilder::class)->refresh(['2026-03-02', '2026-03-02']);
        expect(AttendanceDailyStat::query()->where('work_date', '2026-03-02')->count())->toBe(1);
    });
});

it('puts an overnight shift on the date it starts', function (): void {
    $night = $this->tenant->run(function (): Employee {
        $timetable = Timetable::factory()->between('22:00', '06:00')->create();
        $shift = Shift::factory()->on($timetable, [0, 1, 2, 3, 4])->create();
        $employee = Employee::factory()->inDepartment($this->department)->create(['employee_code' => 'N-1', 'hire_date' => '2026-01-01']);
        ScheduleAssignment::factory()->forEmployee($employee)->create(['shift_id' => $shift->id, 'effective_from' => '2026-01-01', 'anchor_date' => '2026-01-05']);

        return $employee;
    });

    $this->punch('N-1', '2026-03-02 21:55', '2026-03-03 06:02');

    $this->tenant->run(function () use ($night): void {
        $monday = AttendanceDay::query()->where('employee_id', $night->id)->where('work_date', '2026-03-02')->firstOrFail();
        expect($monday->status)->toBe(AttendanceStatus::Present)
            ->and(reportsStatRow('2026-03-02')->present)->toBe(1)
            ->and(reportsStatRow('2026-03-02')->worked_minutes)->toBe($monday->worked_minutes);
    });
});

it('counts the same as the live aggregate, and leaves days outside employment out', function (): void {
    $hr = $this->createTenantUser($this->tenant, RoleName::HrManager);

    $this->tenant->run(function () use ($hr): void {
        $second = Department::factory()->create();
        $other = Employee::factory()->inDepartment($second)->create(['hire_date' => '2026-01-01']);
        $id = $this->employee->id;

        AttendanceDay::factory()->create(['employee_id' => $id, 'work_date' => '2026-03-02', 'department_id' => $this->department->id]);
        AttendanceDay::factory()->late(15)->create(['employee_id' => $id, 'work_date' => '2026-03-03', 'department_id' => $this->department->id]);
        AttendanceDay::factory()->absent()->create(['employee_id' => $id, 'work_date' => '2026-03-04', 'department_id' => $this->department->id]);
        AttendanceDay::factory()->create(['employee_id' => $id, 'work_date' => '2026-03-05', 'department_id' => $this->department->id, 'flags' => ['half_day_leave'], 'worked_minutes' => 240]);
        AttendanceDay::factory()->earlyLeave(40)->create(['employee_id' => $other->id, 'work_date' => '2026-03-02', 'department_id' => $second->id]);
        AttendanceDay::factory()->off(AttendanceStatus::OnLeave)->create(['employee_id' => $other->id, 'work_date' => '2026-03-03', 'department_id' => $second->id]);
        AttendanceDay::factory()->off(AttendanceStatus::NotEmployed)->create(['employee_id' => $other->id, 'work_date' => '2026-03-04']);

        app(DailyStatsBuilder::class)->rebuild(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));

        $params = new ReportParams(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'));
        $rollup = app(StatsReader::class)->aggregate($params, $hr)[0];
        $live = app(AttendanceQuery::class)->aggregate($params->filters(), null, $hr)[0];

        expect(app(StatsReader::class)->usesRollup($params, $hr))->toBeTrue()
            ->and($rollup)->toEqual($live)
            ->and($rollup)->days->toBe(6)->present->toBe(2)->late->toBe(1)->absent->toBe(1)->early_leave->toBe(1)
            ->on_leave->toBe(1)->half_day_leave->toBe(1)->late_days->toBe(1)->late_minutes->toBe(15)
            ->early_leave_minutes->toBe(40)
            ->and(AttendanceDailyStat::query()->where('work_date', '2026-03-04')->sum('days'))->toBe(1);

        $byDepartment = app(StatsReader::class)->aggregate(
            new ReportParams(CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31'), departmentId: $second->id), $hr,
        )[0];
        expect($byDepartment['days'])->toBe(2);
    });
});

it('rebuilds through the command, over every calculated date by default', function (): void {
    $this->tenant->run(function (): void {
        AttendanceDay::factory()->create(['employee_id' => $this->employee->id, 'work_date' => '2026-02-10']);
        AttendanceDay::factory()->absent()->create(['employee_id' => $this->employee->id, 'work_date' => '2026-02-20']);
        expect(AttendanceDailyStat::query()->count())->toBe(0);
    });

    $this->artisan('reports:rebuild-stats', ['--tenant' => [$this->tenant->id]])->assertSuccessful();

    $this->tenant->run(fn () => expect(AttendanceDailyStat::query()->orderBy('work_date')->pluck('work_date')->map->format('Y-m-d')->all())
        ->toBe(['2026-02-10', '2026-02-20']));

    $this->artisan('reports:rebuild-stats', ['--from' => '2026-02-31'])->assertExitCode(2);
    $this->artisan('reports:rebuild-stats', ['--from' => '2026-02-15', '--to' => '2026-02-16'])->assertSuccessful();
    $this->tenant->run(fn () => expect(AttendanceDailyStat::query()->count())->toBe(2));
});
