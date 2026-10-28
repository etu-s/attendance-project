<?php

declare(strict_types=1);

namespace App\Modules\Overtime\Enums;

enum OvertimeStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
