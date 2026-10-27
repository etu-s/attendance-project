<?php

declare(strict_types=1);

namespace App\Modules\Leave\Listeners;

use App\Modules\Leave\Jobs\RecountLeave;
use App\Modules\Scheduling\Events\ScheduleChanged;

/**
 * A changed schedule or holiday can change which days leave counts (a holiday
 * added inside approved leave gives that day back). Past and future leave
 * both: the balance is what matters, not the date.
 */
final class RecountOnScheduleChange
{
    public function handle(ScheduleChanged $event): void
    {
        RecountLeave::dispatch(
            $event->scopeType === 'employee' && $event->scopeId !== null ? [$event->scopeId] : null,
            $event->from,
            $event->to,
        );
    }
}
