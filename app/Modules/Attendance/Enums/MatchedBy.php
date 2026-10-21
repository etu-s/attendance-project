<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Enums;

enum MatchedBy: string
{
    /** The identifier matched an employee code or punch identifier on arrival. */
    case Auto = 'auto';
    /** Someone linked an orphan punch to the employee. */
    case Manual = 'manual';
}
