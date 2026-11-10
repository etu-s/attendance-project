<?php

declare(strict_types=1);

namespace App\Modules\Reports\Enums;

use App\Modules\Attendance\Enums\AttendanceStatus;

/**
 * Exception report types and the attendance statuses each one lists.
 */
enum ExceptionType: string
{
    case Late = 'late';
    case EarlyLeave = 'early_leave';
    case Absent = 'absent';
    case Incomplete = 'incomplete';

    /**
     * @return list<string>
     */
    public function statuses(): array
    {
        return array_map(fn (AttendanceStatus $s) => $s->value, match ($this) {
            self::Late => [AttendanceStatus::Late, AttendanceStatus::LateEarly],
            self::EarlyLeave => [AttendanceStatus::EarlyLeave, AttendanceStatus::LateEarly],
            self::Absent => [AttendanceStatus::Absent],
            self::Incomplete => [AttendanceStatus::Incomplete],
        });
    }
}
