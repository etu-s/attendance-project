<?php

declare(strict_types=1);

namespace App\Modules\Overtime\Enums;

/**
 * Which rate applies (manual entries choose one; calculated rows can mix
 * before/after-shift time on a working day).
 */
enum OvertimeKind: string
{
    case Workday = 'workday';
    case RestDay = 'rest_day';
    case Holiday = 'holiday';
}
