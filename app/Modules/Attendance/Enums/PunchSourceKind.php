<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Enums;

enum PunchSourceKind: string
{
    /** A clock-in terminal. */
    case Device = 'device';
    /** Another system posting punches (HR suite, mobile app, the simulator). */
    case Integration = 'integration';
}
