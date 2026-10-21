<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Enums;

enum BreakKind: string
{
    /** At a set time (start_offset after the timetable start). */
    case Fixed = 'fixed';
    /** A duration taken whenever the employee chooses. */
    case Floating = 'floating';
}
