<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Enums;

enum CorrectionKind: string
{
    /** A missed punch at punched_at. */
    case Add = 'add';
    /** Exclude one raw punch from the calculation. */
    case Ignore = 'ignore';
}
