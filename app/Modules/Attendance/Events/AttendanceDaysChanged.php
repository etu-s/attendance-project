<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Events;

/**
 * One AttendanceCalculator::recalculate() run finished and these work dates
 * changed for at least one employee. Fired once per run (after every
 * per-employee AttendanceRecalculated), inside the same writer job, so
 * consumers that work per date (Reports' rollup) do the work once.
 */
final readonly class AttendanceDaysChanged
{
    /**
     * @param  list<string>  $dates  Y-m-d, sorted, unique
     */
    public function __construct(
        public array $dates,
    ) {}
}
