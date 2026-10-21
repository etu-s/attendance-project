<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Enums;

enum TimetableType: string
{
    /** Fixed start and end (e.g. 09:00–18:00). */
    case Fixed = 'fixed';
    /** Required minutes worked anywhere inside a window, optional core hours. */
    case Flexible = 'flexible';
}
