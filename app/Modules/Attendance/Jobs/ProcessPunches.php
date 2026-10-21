<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Jobs;

use App\Modules\Attendance\Services\PunchProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Processes every raw punch received since the last run, for one tenant.
 *
 * Unique until processing: punches arriving while one is queued add nothing;
 * punches arriving while one runs queue exactly one follow-up. Together with
 * WithoutOverlapping (shared with RecalculateAttendance) a tenant's attendance
 * has a single writer, which keeps SQLite free of "database is locked".
 * QueueTenancyBootstrapper restores the tenant in the worker.
 */
final class ProcessPunches implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 10;

    public int $timeout = 600;

    public readonly string $tenantKey;

    public function __construct()
    {
        $this->tenantKey = (string) tenant()?->getTenantKey();
    }

    public function uniqueId(): string
    {
        return $this->tenantKey;
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [AttendanceWriterLock::for($this->tenantKey)];
    }

    public function handle(PunchProcessor $processor): void
    {
        $processor->processPending();
    }
}
