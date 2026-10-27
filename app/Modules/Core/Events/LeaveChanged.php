<?php

declare(strict_types=1);

namespace App\Modules\Core\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Approved leave of one employee changed between $from and $to (inclusive,
 * Y-m-d): approved, cancelled or recounted. Fired after commit by the Leave
 * module; attendance recalculates those days. Lives in Core so Attendance
 * does not depend on Leave.
 */
final readonly class LeaveChanged implements ShouldDispatchAfterCommit
{
    public function __construct(
        public int $employeeId,
        public string $from,
        public string $to,
    ) {}
}
