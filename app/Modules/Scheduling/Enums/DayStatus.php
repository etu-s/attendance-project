<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Enums;

/**
 * The scheduled state of one employee on one work date. Module 5 turns this
 * plus punches into attendance status (present/late/absent/...).
 */
enum DayStatus: string
{
    /** One or more periods to work. */
    case Working = 'working';
    /** The shift has no timetable that day (weekly off). */
    case Rest = 'rest';
    /** A holiday falls on a working day (periods kept for overtime rules). */
    case Holiday = 'holiday';
    /** A temporary "day off" for the employee. */
    case DayOff = 'day_off';
    /** Employed, but no schedule applies at any level. */
    case Unscheduled = 'unscheduled';
    /** Before the hire date or after the termination date. */
    case NotEmployed = 'not_employed';
}
