<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Listeners;

use App\Modules\Attendance\Jobs\RecalculateAttendance;
use App\Modules\Core\Events\PayrollPeriodUnlocked;

/**
 * An unlocked payroll period is recalculable again: rebuild its days for
 * everyone, so punches, corrections or settings that arrived while it was
 * frozen take effect. Unchanged days are not rewritten.
 */
final class RecalculateOnPeriodUnlock
{
    public function handle(PayrollPeriodUnlocked $event): void
    {
        RecalculateAttendance::dispatch(null, $event->from, $event->to);
    }
}
