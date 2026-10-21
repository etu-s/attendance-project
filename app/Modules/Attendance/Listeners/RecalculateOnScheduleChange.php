<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Listeners;

use App\Modules\Attendance\Jobs\RecalculateAttendance;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Support\TenantClock;
use App\Modules\Scheduling\Events\ScheduleChanged;

/**
 * A schedule, temporary schedule or holiday changed: recalculate the days
 * that already happened. Future days have no attendance yet, and an
 * open-ended change ends today. Scopes other than one employee recalculate
 * everyone employed in the range (simple and always correct; the calculator
 * skips days whose result did not change).
 */
final class RecalculateOnScheduleChange
{
    public function __construct(
        private readonly TenantClock $clock,
    ) {}

    public function handle(ScheduleChanged $event): void
    {
        $today = $this->clock->today();
        $from = LocalDate::parse($event->from);
        $to = $event->to === null ? $today : LocalDate::parse($event->to)->min($today);

        if ($from->greaterThan($today)) {
            return;
        }

        RecalculateAttendance::dispatch(
            $event->scopeType === 'employee' && $event->scopeId !== null ? [$event->scopeId] : null,
            $from->format(LocalDate::FORMAT),
            $to->format(LocalDate::FORMAT),
        );
    }
}
