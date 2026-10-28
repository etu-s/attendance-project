<?php

declare(strict_types=1);

namespace App\Modules\Overtime\Jobs;

use App\Modules\Attendance\Jobs\AttendanceWriterLock;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Overtime\Services\OvertimeCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Recalculates overtime from the attendance already calculated (e.g. after
 * the overtime settings changed). Shares the tenant's attendance writer lock.
 */
final class RecalculateOvertime implements ShouldQueue
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

    public function handle(OvertimeCalculator $calculator): void
    {
        $calculator->recalculate($this->employeeIds, LocalDate::parse($this->from), LocalDate::parse($this->to));
    }
}
