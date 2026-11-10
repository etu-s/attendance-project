<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Enums\CorrectionKind;
use App\Modules\Attendance\Enums\CorrectionStatus;
use App\Modules\Attendance\Enums\PunchDirection;
use App\Modules\Attendance\Events\AttendanceDaysChanged;
use App\Modules\Attendance\Events\AttendanceRecalculated;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\AttendancePeriod;
use App\Modules\Attendance\Models\AttendancePunch;
use App\Modules\Attendance\Models\PunchCorrection;
use App\Modules\Attendance\Support\AttendanceRules;
use App\Modules\Attendance\Support\DayResult;
use App\Modules\Attendance\Support\PunchInput;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Contracts\LeaveCalendar;
use App\Modules\Core\Services\SettingsService;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\EmployeeAssignment;
use App\Modules\Organization\Services\AssignmentService;
use App\Modules\Scheduling\Services\ScheduleResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds attendance_days (+ periods, punches) from matched raw punches,
 * approved corrections and the resolved schedule. Safe to run repeatedly:
 * the same input gives the same rows, and a day whose result did not change
 * is not rewritten (fingerprint). Run it from queued jobs only; writes are
 * one short transaction per employee.
 *
 * Employees are processed in chunks with everything for the chunk loaded up
 * front, so the query count does not grow with the number of days.
 */
final class AttendanceCalculator
{
    public const CHUNK = 50;

    /** Longest span resolved at once (the resolver adds a day either side). */
    public const SPAN_DAYS = 92;

    private const DATETIME = 'Y-m-d H:i:s';

    /**
     * Work dates changed during the current recalculate() run (for the one
     * AttendanceDaysChanged it fires at the end). Saved and restored around
     * a run, so a nested run cannot mix its dates into the outer one.
     *
     * @var array<string, true>
     */
    private array $changedDates = [];

    public function __construct(
        private readonly ScheduleResolver $resolver,
        private readonly DayEvaluator $evaluator,
        private readonly AssignmentService $assignments,
        private readonly SettingsService $settings,
        private readonly LeaveCalendar $leave,
    ) {}

    /**
     * Recalculates [from, to] for the given employees, or for everyone employed
     * in that range when $employeeIds is null. Dates after today (in each
     * day's timezone) are skipped. Returns the number of day rows written.
     *
     * @param  list<int>|null  $employeeIds
     */
    public function recalculate(?array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $from = LocalDate::parse($from->format(LocalDate::FORMAT));
        $to = LocalDate::parse($to->format(LocalDate::FORMAT));

        // No timezone is more than a day ahead of UTC.
        $latest = LocalDate::parse(CarbonImmutable::now('UTC')->addDay()->format(LocalDate::FORMAT));
        if ($to->greaterThan($latest)) {
            $to = $latest;
        }

        if ($to->lessThan($from) || $employeeIds === []) {
            return 0;
        }

        $written = 0;
        $outer = $this->changedDates;
        $this->changedDates = [];
        $start = $from;
        while ($start->lessThanOrEqualTo($to)) {
            $end = $start->addDays(self::SPAN_DAYS - 1)->min($to);

            $this->employees($employeeIds, $start, $end)->chunkById(self::CHUNK, function (EloquentCollection $employees) use ($start, $end, &$written): void {
                $written += $this->chunk($employees, $start, $end);
            });

            $start = $end->addDay();
        }

        $dates = array_keys($this->changedDates);
        sort($dates);
        $this->changedDates = $outer;

        if ($dates !== []) {
            event(new AttendanceDaysChanged($dates));
        }

        return $written;
    }

    /**
     * Named employees are recalculated even outside their employment (a
     * backdated termination turns existing days into "not employed"); for
     * "everyone" only people employed in the range are considered.
     *
     * @param  list<int>|null  $employeeIds
     * @return Builder<Employee>
     */
    private function employees(?array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        $query = Employee::query();

        if ($employeeIds !== null) {
            return $query->whereIn('id', $employeeIds);
        }

        return $query
            ->where('hire_date', '<=', $to->format(LocalDate::FORMAT))
            ->where(fn (Builder $q) => $q->whereNull('termination_date')->orWhere('termination_date', '>=', $from->format(LocalDate::FORMAT)));
    }

    /**
     * @param  EloquentCollection<int, Employee>  $employees
     */
    private function chunk(EloquentCollection $employees, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $ids = $employees->pluck('id')->map(fn ($id) => (int) $id)->all();
        $resolved = $this->resolver->forEmployees($employees, $from->subDay(), $to->addDay());
        $placements = $this->assignments->forEmployees($ids, $from, $to);
        $leave = $this->leave->forEmployees($ids, $from, $to);
        $punches = $this->punches($ids, $from, $to);
        $existing = AttendanceDay::query()
            ->whereIn('employee_id', $ids)
            ->where('work_date', '>=', $from->format(LocalDate::FORMAT))
            ->where('work_date', '<=', $to->format(LocalDate::FORMAT))
            ->get(['id', 'employee_id', 'work_date', 'fingerprint'])
            ->groupBy('employee_id');
        $rules = AttendanceRules::fromSettings($this->settings);
        $now = CarbonImmutable::now('UTC');

        $written = 0;
        foreach ($employees as $employee) {
            $days = $resolved[$employee->id];
            $dates = [];

            for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
                $key = $date->format(LocalDate::FORMAT);
                $localToday = LocalDate::parse($now->setTimezone($days[$key]->timezone)->format(LocalDate::FORMAT));

                if ($date->lessThanOrEqualTo($localToday)) {
                    $dates[] = $key;
                }
            }

            if ($dates === []) {
                continue;
            }

            $results = $this->evaluator->evaluate($days, $punches[$employee->id] ?? [], $dates, $now, $rules, $leave[$employee->id] ?? []);
            $written += $this->write(
                $employee->id,
                $results,
                $placements->get($employee->id, collect()),
                $existing->get($employee->id, collect())->keyBy(fn (AttendanceDay $day) => $day->work_date->format(LocalDate::FORMAT)),
            );
        }

        return $written;
    }

    /**
     * Matched raw punches (not too old) and approved corrections around the
     * range. Three days of margin cover the widest timezone offset plus punch
     * windows of the neighbouring days.
     *
     * @param  list<int>  $ids
     * @return array<int, list<PunchInput>>
     */
    private function punches(array $ids, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $ignored = [];
        $added = [];

        $corrections = PunchCorrection::query()
            ->whereIn('employee_id', $ids)
            ->where('status', CorrectionStatus::Approved->value)
            ->where('work_date', '>=', $from->subDays(2)->format(LocalDate::FORMAT))
            ->where('work_date', '<=', $to->addDays(2)->format(LocalDate::FORMAT))
            ->get();

        foreach ($corrections as $correction) {
            if ($correction->kind === CorrectionKind::Ignore && $correction->raw_punch_id !== null) {
                $ignored[$correction->raw_punch_id] = true;
            } elseif ($correction->kind === CorrectionKind::Add && $correction->punched_at !== null) {
                $added[$correction->employee_id][] = new PunchInput($correction->punched_at, correctionId: $correction->id);
            }
        }

        $rows = DB::table('punch_matches')
            ->join('raw_punches', 'raw_punches.id', '=', 'punch_matches.raw_punch_id')
            ->whereIn('punch_matches.employee_id', $ids)
            ->where('punch_matches.punched_at', '>=', $from->subDays(3)->format(self::DATETIME))
            ->where('punch_matches.punched_at', '<', $to->addDays(3)->format(self::DATETIME))
            ->where('raw_punches.too_old', false)
            ->get(['punch_matches.employee_id', 'raw_punches.id', 'raw_punches.punched_at', 'raw_punches.direction']);

        $punches = $added;
        foreach ($rows as $row) {
            $punches[(int) $row->employee_id][] = new PunchInput(
                CarbonImmutable::createFromFormat(self::DATETIME, (string) $row->punched_at, 'UTC') ?: CarbonImmutable::parse((string) $row->punched_at, 'UTC'),
                rawPunchId: (int) $row->id,
                direction: $row->direction === null ? null : PunchDirection::from((string) $row->direction),
                ignored: isset($ignored[(int) $row->id]),
            );
        }

        return $punches;
    }

    /**
     * Writes the days whose result changed, in one short transaction; an
     * employee with nothing new costs no query at all.
     *
     * @param  array<string, DayResult>  $results
     * @param  Collection<int, EmployeeAssignment>  $placements
     * @param  Collection<string, AttendanceDay>  $existing  date => row (id + fingerprint)
     */
    private function write(int $employeeId, array $results, Collection $placements, Collection $existing): int
    {
        $changes = [];

        foreach ($results as $date => $result) {
            $row = $existing->get($date);

            // Nothing to record before hire / after termination unless a punch says otherwise.
            if ($row === null && $result->status === AttendanceStatus::NotEmployed && ! $result->hasPunches()) {
                continue;
            }

            $placement = $placements->first(fn (EmployeeAssignment $a) => $a->covers(LocalDate::parse((string) $date)));
            [$attributes, $periods, $punches] = $this->rows($result, $placement);
            $fingerprint = sha1(json_encode([$attributes, $periods, $punches], JSON_THROW_ON_ERROR));

            if ($row === null || $row->fingerprint !== $fingerprint) {
                $changes[] = [(string) $date, $row?->id, $attributes + ['fingerprint' => $fingerprint], $periods, $punches];
            }
        }

        if ($changes === []) {
            return 0;
        }

        DB::transaction(function () use ($employeeId, $changes): void {
            $now = CarbonImmutable::now('UTC')->format(self::DATETIME);

            foreach ($changes as [$date, $dayId, $attributes, $periods, $punches]) {
                $attributes['flags'] = $attributes['flags'] === null ? null : json_encode($attributes['flags'], JSON_THROW_ON_ERROR);
                $attributes += ['calculated_at' => $now, 'updated_at' => $now];

                if ($dayId === null) {
                    $dayId = (int) DB::table('attendance_days')->insertGetId($attributes + [
                        'employee_id' => $employeeId,
                        'work_date' => $date,
                        'created_at' => $now,
                    ]);
                } else {
                    DB::table('attendance_days')->where('id', $dayId)->update($attributes);
                    AttendancePunch::query()->where('attendance_day_id', $dayId)->delete();
                    AttendancePeriod::query()->where('attendance_day_id', $dayId)->delete();
                }

                $periodIds = [];
                foreach ($periods as $index => $period) {
                    $periodIds[$index] = (int) DB::table('attendance_periods')->insertGetId($period + ['attendance_day_id' => $dayId]);
                }

                $punchRows = [];
                foreach ($punches as $punch) {
                    $index = $punch['period'];
                    unset($punch['period']);
                    $punchRows[] = $punch + [
                        'attendance_day_id' => $dayId,
                        'attendance_period_id' => $index === null ? null : $periodIds[$index],
                    ];
                }
                if ($punchRows !== []) {
                    DB::table('attendance_punches')->insert($punchRows);
                }
            }
        });

        $dates = array_column($changes, 0);
        foreach ($dates as $date) {
            $this->changedDates[$date] = true;
        }
        event(new AttendanceRecalculated($employeeId, $dates));

        return count($changes);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function rows(DayResult $result, ?EmployeeAssignment $placement): array
    {
        $format = fn (?CarbonImmutable $at): ?string => $at?->utc()->format(self::DATETIME);
        $day = $result->day;

        $attributes = [
            'status' => $result->status->value,
            'department_id' => $placement?->department_id,
            'location_id' => $placement?->location_id,
            'shift_id' => $day->shift?->id,
            'schedule_source' => $day->source,
            'timezone' => $day->timezone,
            'holiday_name' => $day->holiday,
            'leave_name' => $result->leave?->typeName,
            'leave_portion' => $result->leave?->portion->value,
            'first_in_at' => $format($result->firstIn),
            'last_out_at' => $format($result->lastOut),
            'scheduled_minutes' => $result->scheduledMinutes,
            'worked_minutes' => $result->workedMinutes,
            'late_minutes' => $result->lateMinutes,
            'early_leave_minutes' => $result->earlyLeaveMinutes,
            'unmatched_punches' => $result->unmatchedPunches,
            'flags' => $result->flags === [] ? null : $result->flags,
        ];

        $periods = [];
        foreach ($result->periods as $period) {
            $timetable = $period->period->timetable;
            $periods[$period->index] = [
                'position' => $period->index,
                'timetable_id' => $timetable->id,
                'timetable_name' => $timetable->name,
                'flexible' => $timetable->type->value === 'flexible',
                'scheduled_start_at' => $format($period->period->startsAt),
                'scheduled_end_at' => $format($period->period->endsAt),
                'in_at' => $format($period->in),
                'out_at' => $format($period->out),
                'worked_minutes' => $period->workedMinutes,
                'break_minutes' => $period->breakMinutes,
                'late_minutes' => $period->lateMinutes,
                'early_leave_minutes' => $period->earlyLeaveMinutes,
            ];
        }

        $punches = [];
        foreach ($result->punches as $use) {
            $punches[] = [
                'raw_punch_id' => $use->punch->rawPunchId,
                'punch_correction_id' => $use->punch->correctionId,
                'punched_at' => $format($use->punch->at),
                'role' => $use->role->value,
                'period' => $use->periodIndex,
            ];
        }

        return [$attributes, $periods, $punches];
    }
}
