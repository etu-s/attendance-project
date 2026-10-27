<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

use App\Modules\Core\Support\LeaveDay;
use Carbon\CarbonImmutable;

/**
 * Approved leave per employee and work date, readable without touching the
 * Leave module's tables. Implemented by Leave; Core binds an empty default so
 * Attendance works without it. Only counted (scheduled working) days appear.
 */
interface LeaveCalendar
{
    /**
     * @param  list<int>  $employeeIds
     * @return array<int, array<string, LeaveDay>> employee id => Y-m-d => leave
     */
    public function forEmployees(array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): array;
}
