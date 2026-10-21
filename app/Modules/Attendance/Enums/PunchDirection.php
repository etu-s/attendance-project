<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Enums;

/**
 * As reported by the source. Ignored unless attendance.use_punch_direction
 * is on, because devices often get it wrong.
 */
enum PunchDirection: string
{
    case In = 'in';
    case Out = 'out';
}
