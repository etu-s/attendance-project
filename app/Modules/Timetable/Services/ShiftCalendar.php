<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Services;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Timetable\Enums\CycleUnit;
use App\Modules\Timetable\Models\Shift;
use App\Modules\Timetable\Models\ShiftDay;
use App\Modules\Timetable\Support\ScheduledPeriod;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Expands a shift's cycle onto real dates. Scheduling and the attendance
 * engine use this; nothing else should do its own cycle arithmetic.
 *
 * Rules:
 *  - The work date of a period is the date its timetable *starts* on, so a
 *    Mon 22:00–Tue 06:00 period belongs to Monday.
 *  - Times are wall-clock in the given timezone. On a DST change the real
 *    length differs (a 22:00–06:00 night is 7 h when clocks go forward, 9 h
 *    when they go back). A start inside the skipped hour moves forward by the
 *    gap (PHP's rule); an ambiguous wall time resolves to its first occurrence.
 *  - Day cycles count from the anchor date. Week cycles count whole weeks from
 *    the Monday of the anchor's week; day index 0 is that Monday.
 */
final class ShiftCalendar
{
    public const MAX_RANGE_DAYS = 366;

    public function cycleIndex(Shift $shift, CarbonImmutable $anchor, CarbonImmutable $date): int
    {
        $anchor = LocalDate::parse($anchor->format(LocalDate::FORMAT));
        $date = LocalDate::parse($date->format(LocalDate::FORMAT));

        if ($shift->cycle_unit === CycleUnit::Day) {
            $n = $shift->cycle_length;
            $diff = (int) $anchor->diffInDays($date, false);

            return (($diff % $n) + $n) % $n;
        }

        $length = $shift->cycle_length;
        $weeks = (int) floor($anchor->startOfWeek(CarbonImmutable::MONDAY)->diffInDays($date, false) / 7);
        $week = (($weeks % $length) + $length) % $length;

        return $week * 7 + ($date->dayOfWeekIso - 1);
    }

    /**
     * @return array<string, list<ScheduledPeriod>> work date (Y-m-d) => periods, every date in range present
     */
    public function expand(Shift $shift, CarbonImmutable $anchor, CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        $from = LocalDate::parse($from->format(LocalDate::FORMAT));
        $to = LocalDate::parse($to->format(LocalDate::FORMAT));

        if ($to->lessThan($from)) {
            throw new InvalidArgumentException('The range ends before it starts.');
        }
        if ($from->diffInDays($to) >= self::MAX_RANGE_DAYS) {
            throw new InvalidArgumentException('The range is longer than '.self::MAX_RANGE_DAYS.' days.');
        }

        $shift->loadMissing('days.timetable.breaks');
        $byIndex = $shift->days->groupBy('day_index');

        $result = [];
        for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
            $key = $date->format(LocalDate::FORMAT);
            $result[$key] = [];

            foreach ($byIndex->get($this->cycleIndex($shift, $anchor, $date), collect()) as $day) {
                /** @var ShiftDay $day */
                $timetable = $day->timetable;
                $result[$key][] = new ScheduledPeriod(
                    $key,
                    $timetable,
                    $this->wallClock($date, $timetable->start_minute, $timezone),
                    $this->wallClock($date, $timetable->endMinute(), $timezone),
                );
            }

            usort($result[$key], fn (ScheduledPeriod $a, ScheduledPeriod $b) => $a->startsAt <=> $b->startsAt);
        }

        return $result;
    }

    /**
     * The UTC instant of "$minutes after local midnight of $date" in $timezone,
     * where $minutes may exceed a day (it then falls on a later date).
     */
    private function wallClock(CarbonImmutable $date, int $minutes, string $timezone): CarbonImmutable
    {
        $day = $date->addDays(intdiv($minutes, 1440));
        $minute = $minutes % 1440;

        return CarbonImmutable::create($day->year, $day->month, $day->day, intdiv($minute, 60), $minute % 60, 0, $timezone)
            ->utc();
    }
}
