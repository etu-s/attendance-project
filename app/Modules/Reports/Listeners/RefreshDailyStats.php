<?php

declare(strict_types=1);

namespace App\Modules\Reports\Listeners;

use App\Modules\Attendance\Events\AttendanceDaysChanged;
use App\Modules\Reports\Services\DailyStatsBuilder;

/**
 * Runs inside the attendance writer's queued job (synchronously), once per
 * recalculation run with every changed date, so the rollup is written by the
 * tenant's single writer, is never behind attendance, and each date is
 * rebuilt once per run however many employees changed on it.
 */
final class RefreshDailyStats
{
    public function __construct(
        private readonly DailyStatsBuilder $builder,
    ) {}

    public function handle(AttendanceDaysChanged $event): void
    {
        if ($event->dates !== []) {
            $this->builder->refresh($event->dates);
        }
    }
}
