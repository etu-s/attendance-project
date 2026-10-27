<?php

declare(strict_types=1);

namespace App\Modules\Leave\Enums;

enum LeaveRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** Pending and approved requests hold their days: nothing may overlap them. */
    public function isActive(): bool
    {
        return $this === self::Pending || $this === self::Approved;
    }
}
