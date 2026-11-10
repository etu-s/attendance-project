<?php

declare(strict_types=1);

namespace App\Modules\Reports\Support;

/**
 * Report figures from one aggregate row (AttendanceQuery::aggregateColumns()).
 *
 * Definitions (approved for Module 8):
 *  - attended = present + late + early_leave + late_early + incomplete;
 *  - working days = attended + absent (leave, holidays, rest days and days
 *    off are not working days; days still in progress are left out);
 *  - attendance rate = attended ÷ working days;
 *  - punctuality = attended days without late minutes ÷ attended.
 * Rates are percentages with one decimal, null when there is nothing to divide.
 */
final class Kpis
{
    /**
     * @param  array<string, mixed>  $row
     * @return array<string, int|float|null>
     */
    public static function from(array $row): array
    {
        $n = fn (string $key): int => (int) ($row[$key] ?? 0);
        $attended = $n('present') + $n('late') + $n('early_leave') + $n('late_early') + $n('incomplete');
        $working = $attended + $n('absent');

        return [
            'working_days' => $working,
            'attended_days' => $attended,
            'present_days' => $n('present'),
            'late_days' => $n('late_days'),
            'early_leave_days' => $n('early_leave') + $n('late_early'),
            'incomplete_days' => $n('incomplete'),
            'absent_days' => $n('absent'),
            'in_progress_days' => $n('in_progress'),
            'leave_days' => $n('on_leave'),
            'half_day_leave_days' => $n('half_day_leave'),
            'holiday_days' => $n('holiday'),
            'rest_days' => $n('rest') + $n('day_off') + $n('unscheduled'),
            'scheduled_minutes' => $n('scheduled_minutes'),
            'worked_minutes' => $n('worked_minutes'),
            'late_minutes' => $n('late_minutes'),
            'early_leave_minutes' => $n('early_leave_minutes'),
            'attendance_rate' => self::rate($attended, $working),
            'punctuality_rate' => self::rate($attended - $n('late_days'), $attended),
        ];
    }

    public static function rate(int $part, int $whole): ?float
    {
        return $whole > 0 ? round($part * 100 / $whole, 1) : null;
    }
}
