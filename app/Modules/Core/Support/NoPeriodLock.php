<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Core\Contracts\PeriodLock;
use Carbon\CarbonImmutable;

/**
 * Default PeriodLock when the Payroll module is not installed: nothing is
 * ever locked.
 */
final class NoPeriodLock implements PeriodLock
{
    public function lockedDates(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [];
    }

    public function assertOpen(CarbonImmutable $from, ?CarbonImmutable $to, string $what = 'This change'): void {}

    public function overlapping(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [];
    }
}
