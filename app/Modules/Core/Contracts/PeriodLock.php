<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Exceptions\BusinessRuleException;
use Carbon\CarbonImmutable;

/**
 * Locked payroll periods: work dates whose attendance, leave, overtime and
 * schedules are frozen. Implemented by the Payroll module; Core binds an
 * empty default so the other modules work without it.
 *
 * Callers check it before changing anything that affects a work date, and
 * the attendance engine skips locked dates when it recalculates.
 */
interface PeriodLock
{
    /**
     * Locked work dates in [from, to].
     *
     * @return array<string, true> Y-m-d => true
     */
    public function lockedDates(CarbonImmutable $from, CarbonImmutable $to): array;

    /**
     * Throws `409 period_locked` when [from, to] (to = null: open-ended)
     * touches a locked period. $what names the change for the message
     * ("This correction", "This schedule change", ...).
     *
     * @throws BusinessRuleException
     */
    public function assertOpen(CarbonImmutable $from, ?CarbonImmutable $to, string $what = 'This change'): void;

    /**
     * Locked periods overlapping [from, to], earliest first.
     *
     * @return list<array{id: int, name: string, start_date: string, end_date: string}>
     */
    public function overlapping(CarbonImmutable $from, CarbonImmutable $to): array;
}
