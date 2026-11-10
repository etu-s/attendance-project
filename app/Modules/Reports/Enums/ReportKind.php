<?php

declare(strict_types=1);

namespace App\Modules\Reports\Enums;

/**
 * The tabular reports: each can be read page by page and exported.
 */
enum ReportKind: string
{
    case AttendanceSummary = 'attendance-summary';
    case Register = 'register';
    case Exceptions = 'exceptions';
    case Leave = 'leave';
    case Overtime = 'overtime';

    public function label(): string
    {
        return match ($this) {
            self::AttendanceSummary => 'Attendance summary',
            self::Register => 'Monthly register',
            self::Exceptions => 'Late, early and absent',
            self::Leave => 'Leave taken',
            self::Overtime => 'Overtime',
        };
    }
}
