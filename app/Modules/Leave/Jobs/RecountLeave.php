<?php

declare(strict_types=1);

namespace App\Modules\Leave\Jobs;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Leave\Services\LeaveRequestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Recounts active leave requests after a schedule or holiday change. Safe to
 * repeat: an unchanged count writes nothing. QueueTenancyBootstrapper restores
 * the tenant in the worker.
 */
final class RecountLeave implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /**
     * @param  list<int>|null  $employeeIds  null = everyone
     */
    public function __construct(
        public readonly ?array $employeeIds,
        public readonly string $from,
        public readonly ?string $to,
    ) {}

    public function handle(LeaveRequestService $requests): void
    {
        $requests->recountOverlapping(
            $this->employeeIds,
            LocalDate::parse($this->from),
            $this->to === null ? null : LocalDate::parse($this->to),
        );
    }
}
