<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Enums;

/**
 * Result for one employee on one work date. The first group applies to
 * working days; the rest mirror the scheduled state (Scheduling's DayStatus)
 * when no work was expected. Module 6 adds leave.
 */
enum AttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case EarlyLeave = 'early_leave';
    case LateEarly = 'late_early';
    /** A punch is missing (in or out) for at least one period. */
    case Incomplete = 'incomplete';
    /** No punches, or less than the timetable's minimum work. */
    case Absent = 'absent';
    /** The day's last punch window is still open. */
    case InProgress = 'in_progress';
    /** A full day of approved leave (half days are evaluated, flag half_day_leave). */
    case OnLeave = 'on_leave';
    case Holiday = 'holiday';
    case Rest = 'rest';
    case DayOff = 'day_off';
    case Unscheduled = 'unscheduled';
    case NotEmployed = 'not_employed';
}
