<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Core\Contracts\LeaveCalendar;
use Carbon\CarbonImmutable;

/**
 * Default LeaveCalendar when no leave module is installed: nobody is on leave.
 */
final class NoLeaveCalendar implements LeaveCalendar
{
    public function forEmployees(array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [];
    }
}
