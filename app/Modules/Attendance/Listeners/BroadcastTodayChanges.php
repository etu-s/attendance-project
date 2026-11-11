<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Listeners;

use App\Modules\Attendance\Events\AttendanceDaysChanged;
use App\Modules\Attendance\Events\TodayBoardChanged;
use App\Modules\Attendance\Support\TodayChannel;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Support\TenantClock;
use Throwable;

/**
 * After a recalculation run, tells open Today boards to refresh when today
 * or yesterday changed. Nothing is queued when broadcasting is off (the
 * board then polls every 30 seconds).
 */
final class BroadcastTodayChanges
{
    public function __construct(
        private readonly TenantClock $clock,
    ) {}

    public function handle(AttendanceDaysChanged $event): void
    {
        if (in_array(config('broadcasting.default'), ['null', 'log', null], true) || tenant() === null) {
            return;
        }

        $today = $this->clock->today();
        $recent = [$today->format(LocalDate::FORMAT), $today->subDay()->format(LocalDate::FORMAT)];
        $dates = array_values(array_intersect($event->dates, $recent));

        if ($dates === []) {
            return;
        }

        try {
            event(new TodayBoardChanged((string) TodayChannel::currentWorkspace(), $dates));
        } catch (Throwable $e) {
            // Best effort: a misconfigured WebSocket server must never stop
            // punches being processed. Boards fall back to polling.
            report($e);
        }
    }
}
