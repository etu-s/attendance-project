<?php

declare(strict_types=1);

namespace App\Modules\Leave\Enums;

/**
 * Why a leave ledger entry exists. Credits are positive, debits negative.
 */
enum LedgerKind: string
{
    /** Yearly entitlement (credit). */
    case Grant = 'grant';
    /** Monthly accrual (credit). */
    case Accrual = 'accrual';
    /** Balance brought from the previous leave year (credit, or debit if it was negative). */
    case CarryForward = 'carry_forward';
    /** Unused balance above the carry-forward cap at year end (debit). */
    case Expiry = 'expiry';
    /** An approved request (debit). */
    case Request = 'request';
    /** A cancelled approved request given back (credit). */
    case Cancellation = 'cancellation';
    /** An approved request recounted after a schedule or holiday change (either sign). */
    case Recount = 'recount';
    /** Entered by HR, with a reason (either sign). */
    case Adjustment = 'adjustment';

    /** Counts as entitlement in balance summaries. */
    public function isEntitlement(): bool
    {
        return in_array($this, [self::Grant, self::Accrual, self::CarryForward], true);
    }

    /** Counts as leave taken in balance summaries. */
    public function isUsage(): bool
    {
        return in_array($this, [self::Request, self::Cancellation, self::Recount], true);
    }
}
