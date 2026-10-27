<?php

declare(strict_types=1);

namespace App\Modules\Leave\Enums;

/**
 * How a leave type's entitlement is earned (leave:accrue writes the ledger).
 */
enum LeaveAccrual: string
{
    /** Nothing is earned automatically; HR adjusts balances by hand. */
    case None = 'none';
    /** The whole entitlement at the start of the leave year (prorated for joiners). */
    case Yearly = 'yearly';
    /** A twelfth of the entitlement each month of the leave year. */
    case Monthly = 'monthly';
}
