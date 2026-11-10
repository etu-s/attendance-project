<?php

declare(strict_types=1);

namespace App\Modules\Core\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A locked payroll period was unlocked: its days (Y-m-d, inclusive) are
 * recalculable again. Attendance recalculates them so anything that changed
 * while they were frozen (raw punches, settings) is applied. Lives in Core so
 * Attendance does not depend on Payroll.
 */
final readonly class PayrollPeriodUnlocked implements ShouldDispatchAfterCommit
{
    public function __construct(
        public string $from,
        public string $to,
    ) {}
}
