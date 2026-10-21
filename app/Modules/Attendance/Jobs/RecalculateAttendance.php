<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Jobs;

use App\Modules\Attendance\Services\AttendanceCalculator;
use App\Modules\Core\Casts\LocalDate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Recalculates a date range for some employees (null = everyone employed in
 * the range). Safe to repeat, so retries and duplicates are harmless.
 * Shares the tenant's attendance writer lock with ProcessPunches.
 */
final class RecalculateAttendance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 10;

    public int $timeout = 900;

    public readonly string $tenantKey;

    /**
     * @param  list<int>|null  $employeeIds
     */
    public function __construct(
        public readonly ?array $employeeIds,
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

    public function handle(AttendanceCalculator $calculator): void
    {
        $calculator->recalculate($this->employeeIds, LocalDate::parse($this->from), LocalDate::parse($this->to));
    }
}
