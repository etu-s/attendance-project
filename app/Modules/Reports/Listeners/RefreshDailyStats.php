<?php

declare(strict_types=1);

namespace App\Modules\Reports\Listeners;

use App\Modules\Attendance\Events\AttendanceRecalculated;
use App\Modules\Reports\Services\DailyStatsBuilder;

/**
 * Runs inside the attendance writer's queued job (synchronously), so the
 * rollup is written by the tenant's single writer and is never behind the
 * attendance it summarises.
 */
final class RefreshDailyStats
{
    public function __construct(
        private readonly DailyStatsBuilder $builder,
    ) {}

    public function handle(AttendanceRecalculated $event): void
    {
        if ($event->dates !== []) {
            $this->builder->refresh($event->dates);
        }
    }
}
