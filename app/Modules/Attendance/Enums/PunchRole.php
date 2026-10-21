<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Enums;

/**
 * How the calculator used a punch on a day (attendance_punches.role).
 */
enum PunchRole: string
{
    case In = 'in';
    case Out = 'out';
    /** Between the in and the out of a period. */
    case Extra = 'extra';
    /** Within the debounce window of the previous punch. */
    case Duplicate = 'duplicate';
    /** Outside every punch window on a working day; not counted as work. */
    case Unmatched = 'unmatched';
    /** Excluded by an approved "ignore" correction. */
    case Ignored = 'ignored';
}
