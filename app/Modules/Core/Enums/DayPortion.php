<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

/**
 * Which part of a working day something (leave) covers. Halves split the
 * day's scheduled time at its midpoint.
 */
enum DayPortion: string
{
    case Full = 'full';
    case FirstHalf = 'first_half';
    case SecondHalf = 'second_half';

    /** Size in half-days (the unit leave is counted in). */
    public function halves(): int
    {
        return $this === self::Full ? 2 : 1;
    }
}
