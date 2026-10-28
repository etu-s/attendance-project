<?php

declare(strict_types=1);

namespace App\Modules\Overtime\Listeners;

use App\Modules\Attendance\Events\AttendanceRecalculated;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Overtime\Services\OvertimeCalculator;

/**
 * Runs inside the attendance writer's queued job (synchronously), so a
 * tenant's attendance and overtime are written by the same single writer and
 * overtime is never behind the attendance it comes from.
 */
final class RecalculateOnAttendanceChange
{
    public function __construct(
        private readonly OvertimeCalculator $calculator,
    ) {}

    public function handle(AttendanceRecalculated $event): void
    {
        if ($event->dates === []) {
            return;
        }

        $this->calculator->recalculate(
            [$event->employeeId],
            LocalDate::parse(min($event->dates)),
            LocalDate::parse(max($event->dates)),
            $event->dates,
        );
    }
}
