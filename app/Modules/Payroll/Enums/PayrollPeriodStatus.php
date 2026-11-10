<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Enums;

enum PayrollPeriodStatus: string
{
    case Locked = 'locked';
    case Unlocked = 'unlocked';
}
