<?php

declare(strict_types=1);

namespace App\Modules\Reports\Jobs;

use App\Modules\Attendance\Jobs\AttendanceWriterLock;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Reports\Services\DailyStatsBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Rebuilds the attendance rollup for a range (reports:rebuild-stats).
 * Shares the tenant's attendance writer lock, like every attendance write.
 */
final class RebuildDailyStats implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 10;

    public int $timeout = 900;

    public readonly string $tenantKey;

    public function __construct(
        public readonly string $from,
        public readonly string $to,
    ) {
        $this->tenantKey = (string) tenant()?->getTenantKey();
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [AttendanceWriterLock::for($this->tenantKey)];
    }

    public function handle(DailyStatsBuilder $builder): void
    {
        $builder->rebuild(LocalDate::parse($this->from), LocalDate::parse($this->to));
    }
}
