<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Enums;

enum CorrectionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
