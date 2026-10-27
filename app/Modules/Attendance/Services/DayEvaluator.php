<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Enums\PunchDirection;
use App\Modules\Attendance\Enums\PunchRole;
use App\Modules\Attendance\Support\AttendanceRules;
use App\Modules\Attendance\Support\DayResult;
use App\Modules\Attendance\Support\PeriodResult;
use App\Modules\Attendance\Support\PunchInput;
use App\Modules\Attendance\Support\PunchUse;
use App\Modules\Core\Enums\DayPortion;
use App\Modules\Core\Support\LeaveDay;
use App\Modules\Scheduling\Enums\DayStatus;
use App\Modules\Scheduling\Support\ResolvedDay;
use App\Modules\Timetable\Enums\BreakKind;
use App\Modules\Timetable\Enums\TimetableType;
use App\Modules\Timetable\Services\TimetableRules;
use App\Modules\Timetable\Support\ScheduledPeriod;
use Carbon\CarbonImmutable;

/**
 * The attendance rules: resolved schedule + punches in, day results out.
 * Pure calculation (no writes), so the same input always gives the same
 * result and recalculation is safe to repeat.
 *
 * Placing punches:
 *  - Every scheduled period accepts punches from (start - check-in-before)
 *    to (end + check-out-after). A punch inside one or more windows goes to
 *    the period whose start or end is nearest, so a 02:00 punch of a 22:00
 *    shift lands on the previous work date.
 *  - Within attendance.debounce_seconds of the previous counted punch, a
 *    punch is a duplicate (shown, not counted).
 *  - Outside every window it belongs to its local calendar date: on a day
 *    without periods (rest, day off, unscheduled) it counts as off-day work
 *    (first to last punch); on a working day it is "unmatched", not work.
 *
 * Per period: in = first punch, out = last (device direction only when
 * attendance.use_punch_direction is on). A single punch is the in or the out,
 * whichever scheduled edge is nearer. Late/early minutes count from the
 * scheduled start/end once the grace is exceeded (09:12 for 09:00 with 5
 * minutes grace = 12 late). Flexible timetables measure late/early against
 * core hours (if any), and a shortfall against the required minutes counts
 * as early leave. Worked = out - in - unpaid breaks, then rounded down to
 * attendance.rounding_minutes.
 */
final class DayEvaluator
{
    public function __construct(
        private readonly TimetableRules $timetableRules,
    ) {}

    /**
     * @param  array<string, ResolvedDay>  $days  every date to evaluate plus one day either side
     * @param  list<PunchInput>  $punches  the employee's punches around those dates, any order
     * @param  list<string>  $dates  work dates to evaluate (keys of $days)
     * @param  array<string, LeaveDay>  $leave  approved leave by work date
     * @return array<string, DayResult>
     */
    public function evaluate(array $days, array $punches, array $dates, CarbonImmutable $now, AttendanceRules $rules, array $leave = []): array
    {
        $windows = $this->windows($days);
        $placed = $this->place($punches, $windows, $days, $rules);

        $results = [];
        foreach ($dates as $date) {
            // Leave only matters on a working day; holidays and rest days stay what they are.
            $dayLeave = $days[$date]->status === DayStatus::Working ? ($leave[$date] ?? null) : null;
            $results[$date] = $this->day($days[$date], $placed[$date] ?? [], $windows[$date] ?? [], $now, $rules, $dayLeave);
        }

        return $results;
    }

    /**
     * @param  array<string, ResolvedDay>  $days
     * @return array<string, list<array{index: int, period: ScheduledPeriod, rules: array<string, int>, opens: int, closes: int}>>
     */
    private function windows(array $days): array
    {
        $windows = [];

        foreach ($days as $date => $day) {
            foreach ($day->periods as $index => $period) {
                $rules = $this->timetableRules->for($period->timetable);
                $windows[$date][] = [
                    'index' => $index,
                    'period' => $period,
                    'rules' => $rules,
                    'opens' => $period->startsAt->getTimestamp() - $rules['check_in_before_minutes'] * 60,
                    'closes' => $period->endsAt->getTimestamp() + $rules['check_out_after_minutes'] * 60,
                ];
            }
        }

        return $windows;
    }

    /**
     * @param  list<PunchInput>  $punches
     * @param  array<string, list<array{index: int, period: ScheduledPeriod, rules: array<string, int>, opens: int, closes: int}>>  $windows
     * @param  array<string, ResolvedDay>  $days
     * @return array<string, list<array{punch: PunchInput, role: PunchRole|null, period: int|null}>>
     */
    private function place(array $punches, array $windows, array $days, AttendanceRules $rules): array
    {
        usort($punches, fn (PunchInput $a, PunchInput $b) => [$a->timestamp(), $a->rawPunchId ?? PHP_INT_MAX, $a->correctionId ?? 0]
            <=> [$b->timestamp(), $b->rawPunchId ?? PHP_INT_MAX, $b->correctionId ?? 0]);

        $placed = [];
        $last = null;
        $lastTarget = [null, null];

        foreach ($punches as $punch) {
            if ($punch->ignored) {
                [$date, $period] = $this->locate($punch, $windows, $days);
                if ($date !== null) {
                    $placed[$date][] = ['punch' => $punch, 'role' => PunchRole::Ignored, 'period' => $period];
                }

                continue;
            }

            if ($last !== null && $rules->debounceSeconds > 0 && $punch->timestamp() - $last->timestamp() <= $rules->debounceSeconds) {
                if ($lastTarget[0] !== null) {
                    $placed[$lastTarget[0]][] = ['punch' => $punch, 'role' => PunchRole::Duplicate, 'period' => $lastTarget[1]];
                }

                continue;
            }

            $last = $punch;
            $lastTarget = $this->locate($punch, $windows, $days);
            if ($lastTarget[0] !== null) {
                $placed[$lastTarget[0]][] = ['punch' => $punch, 'role' => null, 'period' => $lastTarget[1]];
            }
        }

        return $placed;
    }

    /**
     * @param  array<string, list<array{index: int, period: ScheduledPeriod, rules: array<string, int>, opens: int, closes: int}>>  $windows
     * @param  array<string, ResolvedDay>  $days
     * @return array{0: string|null, 1: int|null} [work date, period index]
     */
    private function locate(PunchInput $punch, array $windows, array $days): array
    {
        $t = $punch->timestamp();
        $best = [null, null];
        $bestDistance = PHP_INT_MAX;

        foreach ($windows as $date => $list) {
            foreach ($list as $window) {
                if ($t < $window['opens'] || $t > $window['closes']) {
                    continue;
                }

                $distance = min(
                    abs($t - $window['period']->startsAt->getTimestamp()),
                    abs($t - $window['period']->endsAt->getTimestamp()),
                );

                if ($distance < $bestDistance) {
                    $best = [(string) $date, $window['index']];
                    $bestDistance = $distance;
                }
            }
        }

        if ($best[0] !== null) {
            return $best;
        }

        foreach ($days as $date => $day) {
            if ($punch->at->setTimezone($day->timezone)->format('Y-m-d') === (string) $date) {
                return [(string) $date, null];
            }
        }

        return [null, null];
    }

    /**
     * @param  list<array{punch: PunchInput, role: PunchRole|null, period: int|null}>  $placed
     * @param  list<array{index: int, period: ScheduledPeriod, rules: array<string, int>, opens: int, closes: int}>  $windows
     */
    private function day(ResolvedDay $day, array $placed, array $windows, CarbonImmutable $now, AttendanceRules $rules, ?LeaveDay $leave): DayResult
    {
        $halfLeave = $leave !== null && $leave->portion !== DayPortion::Full ? $leave->portion : null;
        $midpoint = $halfLeave !== null ? $this->midpoint($windows) : null;

        $uses = [];
        $byPeriod = [];
        $loose = [];

        foreach ($placed as $item) {
            if ($item['role'] !== null) {
                $uses[] = new PunchUse($item['punch'], $item['role'], $item['period']);
            } elseif ($item['period'] !== null) {
                $byPeriod[$item['period']][] = $item['punch'];
            } else {
                $loose[] = $item['punch'];
            }
        }

        $periods = [];
        foreach ($windows as $window) {
            [$result, $periodUses] = $this->period($window, $byPeriod[$window['index']] ?? [], $rules, $halfLeave, $midpoint);
            $periods[] = $result;
            array_push($uses, ...$periodUses);
        }

        $flags = $halfLeave !== null ? ['half_day_leave'] : [];
        $firstIn = null;
        $lastOut = null;
        $worked = 0;
        $unmatched = 0;

        if ($day->periods === [] && $day->status !== DayStatus::NotEmployed && $loose !== []) {
            // Work on a rest day, day off or unscheduled day: first to last punch.
            $in = $loose[0];
            $out = count($loose) > 1 ? $loose[count($loose) - 1] : null;
            foreach ($loose as $punch) {
                $uses[] = new PunchUse($punch, match (true) {
                    $punch === $in => PunchRole::In,
                    $punch === $out => PunchRole::Out,
                    default => PunchRole::Extra,
                });
            }
            $firstIn = $in->at;
            $lastOut = $out?->at;
            $worked = $out !== null ? $this->round(intdiv($out->timestamp() - $in->timestamp(), 60), $rules) : 0;
            $flags[] = 'off_day_work';
        } else {
            foreach ($loose as $punch) {
                $uses[] = new PunchUse($punch, PunchRole::Unmatched);
                $unmatched++;
            }
        }

        $late = 0;
        $early = 0;
        foreach ($periods as $period) {
            $worked += $period->workedMinutes;
            $late += $period->lateMinutes;
            $early += $period->earlyLeaveMinutes;
            if ($period->in !== null && ($firstIn === null || $period->in->lessThan($firstIn))) {
                $firstIn = $period->in;
            }
            if ($period->out !== null && ($lastOut === null || $period->out->greaterThan($lastOut))) {
                $lastOut = $period->out;
            }
        }

        // A full day of approved leave is leave, punches or not (they stay recorded for overtime).
        $fullLeave = $leave !== null && $halfLeave === null;
        $status = $fullLeave ? AttendanceStatus::OnLeave : $this->status($day, $periods, $windows, $now, $worked, $flags, $halfLeave !== null);

        if ($day->status !== DayStatus::Working || $fullLeave) {
            // Late/early only mean something when work was expected.
            $late = 0;
            $early = 0;
        }

        usort($uses, fn (PunchUse $a, PunchUse $b) => [$a->punch->timestamp(), $a->punch->rawPunchId ?? PHP_INT_MAX]
            <=> [$b->punch->timestamp(), $b->punch->rawPunchId ?? PHP_INT_MAX]);

        $scheduled = $day->status === DayStatus::Working && ! $fullLeave
            ? array_sum(array_map(fn (PeriodResult $p) => $this->scheduledMinutes($p->period), $periods))
            : 0;
        if ($halfLeave !== null) {
            $scheduled = intdiv($scheduled, 2);
        }

        return new DayResult(
            $day,
            $status,
            $periods,
            $uses,
            $firstIn,
            $lastOut,
            $scheduled,
            $worked,
            $late,
            $early,
            $unmatched,
            array_values(array_unique($flags)),
            $leave,
        );
    }

    /**
     * The instant halfway through the day's scheduled time (first start to
     * last end), where a half-day leave splits the day.
     *
     * @param  list<array{index: int, period: ScheduledPeriod, rules: array<string, int>, opens: int, closes: int}>  $windows
     */
    private function midpoint(array $windows): ?int
    {
        if ($windows === []) {
            return null;
        }

        $start = min(array_map(fn (array $w) => $w['period']->startsAt->getTimestamp(), $windows));
        $end = max(array_map(fn (array $w) => $w['period']->endsAt->getTimestamp(), $windows));

        return intdiv($start + $end, 2);
    }

    /**
     * @param  list<PeriodResult>  $periods
     * @param  list<array{index: int, period: ScheduledPeriod, rules: array<string, int>, opens: int, closes: int}>  $windows
     * @param  list<string>  $flags
     */
    private function status(ResolvedDay $day, array $periods, array $windows, CarbonImmutable $now, int $worked, array &$flags, bool $halfLeave): AttendanceStatus
    {
        $mapped = match ($day->status) {
            DayStatus::Working => null,
            DayStatus::Rest => AttendanceStatus::Rest,
            DayStatus::Holiday => AttendanceStatus::Holiday,
            DayStatus::DayOff => AttendanceStatus::DayOff,
            DayStatus::Unscheduled => AttendanceStatus::Unscheduled,
            DayStatus::NotEmployed => AttendanceStatus::NotEmployed,
        };

        if ($mapped !== null) {
            return $mapped;
        }

        // Until the last punch window closes the day can still change.
        if ($now->getTimestamp() < max(array_column($windows, 'closes'))) {
            return AttendanceStatus::InProgress;
        }

        // Periods inside the half-day leave are not required.
        $periods = array_values(array_filter($periods, fn (PeriodResult $p) => ! $p->excused));
        if ($periods === []) {
            return AttendanceStatus::Present;
        }

        $missing = array_filter($periods, fn (PeriodResult $p) => $p->isMissing());
        if (count($missing) === count($periods)) {
            return AttendanceStatus::Absent;
        }

        $incomplete = array_filter($periods, fn (PeriodResult $p) => $p->isIncomplete());
        if ($incomplete !== [] || $missing !== []) {
            foreach ($periods as $period) {
                if ($period->isMissing()) {
                    $flags[] = 'missed_period';
                } elseif ($period->in === null) {
                    $flags[] = 'missing_in';
                } elseif ($period->out === null) {
                    $flags[] = 'missing_out';
                }
            }

            return AttendanceStatus::Incomplete;
        }

        $minimum = array_sum(array_map(fn (PeriodResult $p) => (int) $p->period->timetable->min_work_minutes, $periods));
        if ($halfLeave) {
            $minimum = intdiv($minimum, 2);
        }
        if ($minimum > 0 && $worked < $minimum) {
            // Below the timetable minimum counts as absent; worked minutes stay visible.
            $flags[] = 'below_minimum';

            return AttendanceStatus::Absent;
        }

        $late = array_sum(array_map(fn (PeriodResult $p) => $p->lateMinutes, $periods)) > 0;
        $early = array_sum(array_map(fn (PeriodResult $p) => $p->earlyLeaveMinutes, $periods)) > 0;

        return match (true) {
            $late && $early => AttendanceStatus::LateEarly,
            $late => AttendanceStatus::Late,
            $early => AttendanceStatus::EarlyLeave,
            default => AttendanceStatus::Present,
        };
    }

    /**
     * @param  array{index: int, period: ScheduledPeriod, rules: array<string, int>, opens: int, closes: int}  $window
     * @param  list<PunchInput>  $punches  in time order
     * @return array{0: PeriodResult, 1: list<PunchUse>}
     */
    private function period(array $window, array $punches, AttendanceRules $rules, ?DayPortion $halfLeave = null, ?int $midpoint = null): array
    {
        $period = $window['period'];
        $timetable = $period->timetable;
        [$in, $out] = $this->inOut($period, $punches, $rules);

        $uses = [];
        foreach ($punches as $punch) {
            $role = match (true) {
                $punch === $in => PunchRole::In,
                $punch === $out => PunchRole::Out,
                default => PunchRole::Extra,
            };
            $uses[] = new PunchUse($punch, $role, $window['index']);
        }

        $start = $period->startsAt->getTimestamp();
        $end = $period->endsAt->getTimestamp();
        $flexible = $timetable->type === TimetableType::Flexible;
        $lateFrom = $start;
        $earlyUntil = $end;

        if ($flexible) {
            $hasCore = $timetable->core_start_offset !== null && (int) $timetable->core_duration_minutes > 0;
            $lateFrom = $hasCore ? $start + (int) $timetable->core_start_offset * 60 : null;
            $earlyUntil = $hasCore && $lateFrom !== null ? $lateFrom + (int) $timetable->core_duration_minutes * 60 : null;
        }

        // Half-day leave: the leave half is excused; the expected start (first
        // half off) or end (second half off) moves to the day's midpoint.
        $excused = false;
        $required = (int) $timetable->required_minutes;
        if ($halfLeave !== null && $midpoint !== null) {
            $excused = $halfLeave === DayPortion::FirstHalf ? $end <= $midpoint : $start >= $midpoint;
            if ($halfLeave === DayPortion::FirstHalf && $lateFrom !== null) {
                $lateFrom = max($lateFrom, $midpoint);
            }
            if ($halfLeave === DayPortion::SecondHalf && $earlyUntil !== null) {
                $earlyUntil = min($earlyUntil, $midpoint);
            }
            $required = intdiv($required, 2);
        }

        $late = ! $excused && $in !== null && $lateFrom !== null && $in->timestamp() > $lateFrom + $window['rules']['late_grace_minutes'] * 60
            ? intdiv($in->timestamp() - $lateFrom, 60)
            : 0;
        $early = ! $excused && $out !== null && $earlyUntil !== null && $out->timestamp() < $earlyUntil - $window['rules']['early_leave_grace_minutes'] * 60
            ? intdiv($earlyUntil - $out->timestamp(), 60)
            : 0;

        $worked = 0;
        $break = 0;
        if ($in !== null && $out !== null) {
            $span = intdiv($out->timestamp() - $in->timestamp(), 60);
            $break = min($span, $this->unpaidBreakMinutes($period, $in->timestamp(), $out->timestamp()));
            $worked = $this->round($span - $break, $rules);

            if ($flexible && ! $excused && $required > 0 && $worked < $required) {
                $early = max($early, $required - $worked);
            }
        }

        return [
            new PeriodResult($window['index'], $period, $in?->at, $out?->at, $worked, $break, $late, $early, $excused),
            $uses,
        ];
    }

    /**
     * @param  list<PunchInput>  $punches
     * @return array{0: PunchInput|null, 1: PunchInput|null}
     */
    private function inOut(ScheduledPeriod $period, array $punches, AttendanceRules $rules): array
    {
        if ($punches === []) {
            return [null, null];
        }

        if ($rules->useDirection) {
            $in = $this->first($punches, PunchDirection::In) ?? $this->first($punches, null);
            $out = $this->last($punches, PunchDirection::Out) ?? $this->last($punches, null);

            if ($in !== null && $out === $in) {
                return $this->single($period, $in);
            }
            if ($in !== null && $out !== null && $out->timestamp() <= $in->timestamp()) {
                $out = null;
            }

            return [$in, $out];
        }

        if (count($punches) === 1) {
            return $this->single($period, $punches[0]);
        }

        return [$punches[0], $punches[count($punches) - 1]];
    }

    /**
     * A lone punch is the in or the out, whichever scheduled edge is nearer.
     *
     * @return array{0: PunchInput|null, 1: PunchInput|null}
     */
    private function single(ScheduledPeriod $period, PunchInput $punch): array
    {
        $t = $punch->timestamp();
        $toStart = abs($t - $period->startsAt->getTimestamp());
        $toEnd = abs($t - $period->endsAt->getTimestamp());

        return $toStart <= $toEnd ? [$punch, null] : [null, $punch];
    }

    /**
     * @param  list<PunchInput>  $punches
     */
    private function first(array $punches, ?PunchDirection $direction): ?PunchInput
    {
        foreach ($punches as $punch) {
            if ($punch->direction === $direction) {
                return $punch;
            }
        }

        return null;
    }

    /**
     * @param  list<PunchInput>  $punches
     */
    private function last(array $punches, ?PunchDirection $direction): ?PunchInput
    {
        return $this->first(array_reverse($punches), $direction);
    }

    /**
     * Fixed unpaid breaks count only where they overlap the time worked;
     * floating unpaid breaks are taken whenever there is work.
     */
    private function unpaidBreakMinutes(ScheduledPeriod $period, int $in, int $out): int
    {
        $start = $period->startsAt->getTimestamp();
        $minutes = 0;

        foreach ($period->timetable->breaks as $break) {
            if ($break->is_paid) {
                continue;
            }

            if ($break->kind === BreakKind::Fixed && $break->start_offset !== null) {
                $from = $start + $break->start_offset * 60;
                $to = $from + $break->duration_minutes * 60;
                $minutes += intdiv(max(0, min($to, $out) - max($from, $in)), 60);
            } else {
                $minutes += $break->duration_minutes;
            }
        }

        return $minutes;
    }

    /**
     * Expected work: the real length (a DST night is an hour shorter or
     * longer) minus unpaid breaks; for a flexible timetable, its required time.
     */
    private function scheduledMinutes(ScheduledPeriod $period): int
    {
        $timetable = $period->timetable;

        if ($timetable->type === TimetableType::Flexible) {
            return (int) $timetable->required_minutes;
        }

        $unpaid = (int) $timetable->breaks->where('is_paid', false)->sum('duration_minutes');

        return max(0, $period->minutes() - $unpaid);
    }

    private function round(int $minutes, AttendanceRules $rules): int
    {
        $minutes = max(0, $minutes);

        return $rules->roundingMinutes > 0 ? intdiv($minutes, $rules->roundingMinutes) * $rules->roundingMinutes : $minutes;
    }
}
