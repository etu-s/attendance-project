<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Enums;

enum OrphanStatus: string
{
    /** Unknown identifier, waiting for someone to link or dismiss it. */
    case Open = 'open';
    /** Linked to an employee; the punch now counts for them. */
    case Resolved = 'resolved';
    /** Not an employee's punch (test badge, visitor); never counted. */
    case Dismissed = 'dismissed';
}
