<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Listeners;

use App\Modules\Attendance\Jobs\RecalculateAttendance;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Events\LeaveChanged;
use App\Modules\Core\Support\TenantClock;

/**
 * Approved leave changed: recalculate the days that already happened (future
 * days get their status when they are evaluated).
 */
final class RecalculateOnLeaveChange
{
    public function __construct(
        private readonly TenantClock $clock,
    ) {}

    public function handle(LeaveChanged $event): void
    {
        $today = $this->clock->today();
        $from = LocalDate::parse($event->from);

        if ($from->greaterThan($today)) {
            return;
        }

        RecalculateAttendance::dispatch(
            [$event->employeeId],
            $from->format(LocalDate::FORMAT),
            LocalDate::parse($event->to)->min($today)->format(LocalDate::FORMAT),
        );
    }
}
