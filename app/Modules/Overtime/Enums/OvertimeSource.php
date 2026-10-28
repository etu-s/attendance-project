<?php

declare(strict_types=1);

namespace App\Modules\Overtime\Enums;

enum OvertimeSource: string
{
    /** Derived from attendance; rebuilt as attendance changes. */
    case Calculated = 'calculated';
    /** Entered by HR for work no punch captured. */
    case Manual = 'manual';
}
