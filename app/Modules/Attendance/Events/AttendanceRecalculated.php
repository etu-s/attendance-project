<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Events;

/**
 * Attendance days of one employee changed (written by AttendanceCalculator,
 * after its transaction committed). Overtime recalculates those days.
 */
final readonly class AttendanceRecalculated
{
    /**
     * @param  list<string>  $dates  Y-m-d work dates whose result changed
     */
    public function __construct(
        public int $employeeId,
        public array $dates,
    ) {}
}
