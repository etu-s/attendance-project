<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Enums\DayPortion;
use App\Modules\Leave\Support\LeaveYears;
use App\Modules\Organization\Models\Employee;
use App\Modules\Scheduling\Enums\DayStatus;
use App\Modules\Scheduling\Services\ScheduleResolver;
use Carbon\CarbonImmutable;

/**
 * Which days of a range count as leave: only days the employee is scheduled
 * to work (ScheduleResolver). Holidays, rest days, temporary days off and days
 * outside employment cost nothing.
 */
final class LeaveDayCounter
{
    public function __construct(
        private readonly ScheduleResolver $resolver,
        private readonly LeaveYears $years,
    ) {}

    /**
     * @return list<array{date: string, portion: DayPortion, leave_year: int}>
     */
    public function count(Employee $employee, CarbonImmutable $from, CarbonImmutable $to, bool $halfStart, bool $halfEnd): array
    {
        $first = $from->format(LocalDate::FORMAT);
        $last = $to->format(LocalDate::FORMAT);
        $counted = [];

        foreach ($this->resolver->forEmployee($employee, $from, $to) as $date => $day) {
            if ($day->status !== DayStatus::Working) {
                continue;
            }

            $portion = match (true) {
                $date === $first && $halfStart => DayPortion::SecondHalf,
                $date === $last && $halfEnd => DayPortion::FirstHalf,
                default => DayPortion::Full,
            };

            $counted[] = ['date' => (string) $date, 'portion' => $portion, 'leave_year' => $this->years->of(LocalDate::parse((string) $date))];
        }

        return $counted;
    }

    /**
     * @param  list<array{date: string, portion: DayPortion, leave_year: int}>  $days
     * @return array<int, int> leave year => half-days
     */
    public static function byYear(array $days): array
    {
        $years = [];
        foreach ($days as $day) {
            $years[$day['leave_year']] = ($years[$day['leave_year']] ?? 0) + $day['portion']->halves();
        }

        return $years;
    }
}
