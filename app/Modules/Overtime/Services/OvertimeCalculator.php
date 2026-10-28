<?php

declare(strict_types=1);

namespace App\Modules\Overtime\Services;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\AttendancePeriod;
use App\Modules\Attendance\Services\AttendanceQuery;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Services\SettingsService;
use App\Modules\Organization\Models\Employee;
use App\Modules\Overtime\Enums\OvertimeSource;
use App\Modules\Overtime\Enums\OvertimeStatus;
use App\Modules\Overtime\Models\OvertimeRecord;
use App\Modules\Overtime\Support\OvertimeSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Overtime from calculated attendance (approved 2026-10-27):
 *  - working day: time after the scheduled end, but never more than the day's
 *    net surplus (worked − scheduled), so a late start is made up first; time
 *    before the start only with overtime.count_early_arrival;
 *  - rest day, day off, unscheduled day: all worked time (rest-day rate);
 *  - holiday: all worked time (holiday rate);
 *  - a full day of leave, an unfinished day or a day outside employment: none.
 * Then: below overtime.min_minutes → none; round down to
 * overtime.rounding_minutes; cap at overtime.daily_cap_minutes (0 = none).
 *
 * Safe to repeat. A record already approved or rejected whose result changes
 * goes back to pending with changed_after_decision (the audit log keeps the
 * earlier decision); an undecided record that drops to nothing is removed.
 */
final class OvertimeCalculator
{
    private const CHUNK = 200;

    public function __construct(
        private readonly AttendanceQuery $attendance,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  list<int>|null  $employeeIds  null = everyone
     * @param  list<string>|null  $dates  only these work dates within the range
     * @return int records created, changed or removed
     */
    public function recalculate(?array $employeeIds, CarbonImmutable $from, CarbonImmutable $to, ?array $dates = null): int
    {
        $ids = $employeeIds ?? Employee::withTrashed()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $rules = OvertimeSettings::fromSettings($this->settings);
        $changed = 0;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $days = $this->attendance->withPeriods($chunk, $from, $to, $dates);
            $existing = OvertimeRecord::withTrashed()
                ->whereIn('employee_id', $chunk)
                ->where('source', OvertimeSource::Calculated->value)
                ->where('work_date', '>=', $from->format(LocalDate::FORMAT))
                ->where('work_date', '<=', $to->format(LocalDate::FORMAT))
                ->get()
                ->keyBy(fn (OvertimeRecord $r) => $r->employee_id.'|'.$r->work_date->format(LocalDate::FORMAT));

            $changed += $this->apply($days, $existing, $rules);
        }

        return $changed;
    }

    /**
     * The overtime minutes of one calculated day, or null when there are none.
     *
     * @return array{before: int, after: int, rest_day: int, holiday: int}|null
     */
    public function evaluate(AttendanceDay $day, OvertimeSettings $rules): ?array
    {
        $minutes = ['before' => 0, 'after' => 0, 'rest_day' => 0, 'holiday' => 0];

        switch ($day->status) {
            case AttendanceStatus::InProgress:
            case AttendanceStatus::NotEmployed:
            case AttendanceStatus::OnLeave:
                return null;
            case AttendanceStatus::Rest:
            case AttendanceStatus::DayOff:
            case AttendanceStatus::Unscheduled:
                $minutes['rest_day'] = $day->worked_minutes;
                break;
            case AttendanceStatus::Holiday:
                $minutes['holiday'] = $day->worked_minutes;
                break;
            default:
                [$minutes['before'], $minutes['after']] = $this->workday($day, $rules);
        }

        $total = array_sum($minutes);
        if ($total === 0 || $total < $rules->minMinutes) {
            return null;
        }

        $kept = $rules->roundingMinutes > 0 ? intdiv($total, $rules->roundingMinutes) * $rules->roundingMinutes : $total;
        if ($rules->dailyCapMinutes > 0) {
            $kept = min($kept, $rules->dailyCapMinutes);
        }
        if ($kept === 0) {
            return null;
        }

        // Trim what rounding and the cap removed: early arrival first, then after the shift.
        $excess = $total - $kept;
        foreach (['before', 'after', 'rest_day', 'holiday'] as $part) {
            $cut = min($excess, $minutes[$part]);
            $minutes[$part] -= $cut;
            $excess -= $cut;
        }

        return $minutes;
    }

    /**
     * @return array{0: int, 1: int} [before, after]
     */
    private function workday(AttendanceDay $day, OvertimeSettings $rules): array
    {
        $after = 0;
        $before = 0;

        foreach ($day->periods as $period) {
            /** @var AttendancePeriod $period */
            if ($period->out_at !== null && $period->out_at->greaterThan($period->scheduled_end_at)) {
                $after += intdiv($period->out_at->getTimestamp() - $period->scheduled_end_at->getTimestamp(), 60);
            }
            if ($rules->countEarlyArrival && $period->in_at !== null && $period->in_at->lessThan($period->scheduled_start_at)) {
                $before += intdiv($period->scheduled_start_at->getTimestamp() - $period->in_at->getTimestamp(), 60);
            }
        }

        // Never more than the day's net surplus: a late start is made up first.
        $total = min($after + $before, max(0, $day->worked_minutes - $day->scheduled_minutes));
        $afterKept = min($after, $total);

        return [$total - $afterKept, $afterKept];
    }

    /**
     * @param  Collection<int, AttendanceDay>  $days
     * @param  Collection<string, OvertimeRecord>  $existing
     */
    private function apply(Collection $days, Collection $existing, OvertimeSettings $rules): int
    {
        $changed = 0;

        foreach ($days as $day) {
            $key = $day->employee_id.'|'.$day->work_date->format(LocalDate::FORMAT);
            $record = $existing->get($key);
            $minutes = $this->evaluate($day, $rules);

            if ($minutes === null) {
                $changed += $this->clear($record);

                continue;
            }

            $attributes = [
                'before_minutes' => $minutes['before'],
                'after_minutes' => $minutes['after'],
                'rest_day_minutes' => $minutes['rest_day'],
                'holiday_minutes' => $minutes['holiday'],
                'calculated_minutes' => array_sum($minutes),
                'rate_workday' => $rules->rateWorkday,
                'rate_rest_day' => $rules->rateRestDay,
                'rate_holiday' => $rules->rateHoliday,
                'weighted_minutes' => $rules->weighted($minutes),
            ];

            if ($record === null) {
                OvertimeRecord::query()->create($attributes + [
                    'employee_id' => $day->employee_id,
                    'work_date' => $day->work_date,
                    'source' => OvertimeSource::Calculated,
                    'status' => OvertimeStatus::Pending,
                ]);
                $changed++;

                continue;
            }

            if ($record->trashed()) {
                $record->restore();
            }

            $record->fill($attributes);
            if (! $record->isDirty()) {
                continue;
            }

            $this->reopenIfDecided($record);
            $record->save();
            $changed++;
        }

        return $changed;
    }

    /**
     * No overtime any more: an undecided record goes; a decided one is reopened at zero.
     */
    private function clear(?OvertimeRecord $record): int
    {
        if ($record === null || $record->trashed()) {
            return 0;
        }

        if ($record->status === OvertimeStatus::Pending && ! $record->changed_after_decision) {
            $record->forceDelete();

            return 1;
        }

        $record->fill([
            'before_minutes' => 0, 'after_minutes' => 0, 'rest_day_minutes' => 0, 'holiday_minutes' => 0,
            'calculated_minutes' => 0, 'weighted_minutes' => 0,
        ]);
        if (! $record->isDirty()) {
            return 0;
        }

        $this->reopenIfDecided($record);
        $record->save();

        return 1;
    }

    private function reopenIfDecided(OvertimeRecord $record): void
    {
        if ($record->status === OvertimeStatus::Pending) {
            return;
        }

        $record->fill([
            'status' => OvertimeStatus::Pending,
            'changed_after_decision' => true,
            'approved_minutes' => null,
            'approved_weighted_minutes' => null,
        ]);
    }
}
