<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Contracts\LeaveCalendar;
use App\Modules\Core\Enums\DayPortion;
use App\Modules\Core\Support\LeaveDay;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Core's LeaveCalendar: approved leave per employee and counted day, in one
 * query for any number of employees (the attendance calculator works in chunks).
 */
final class LeaveCalendarService implements LeaveCalendar
{
    public function forEmployees(array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($employeeIds === []) {
            return [];
        }

        $rows = DB::table('leave_request_days')
            ->join('leave_requests', 'leave_requests.id', '=', 'leave_request_days.leave_request_id')
            ->join('leave_types', 'leave_types.id', '=', 'leave_requests.leave_type_id')
            ->whereIn('leave_request_days.employee_id', $employeeIds)
            ->where('leave_request_days.date', '>=', $from->format(LocalDate::FORMAT))
            ->where('leave_request_days.date', '<=', $to->format(LocalDate::FORMAT))
            ->where('leave_requests.status', LeaveRequestStatus::Approved->value)
            ->whereNull('leave_requests.deleted_at')
            ->get([
                'leave_request_days.employee_id', 'leave_request_days.date', 'leave_request_days.portion',
                'leave_requests.id as request_id', 'leave_types.name', 'leave_types.code', 'leave_types.is_paid',
            ]);

        $calendar = [];
        foreach ($rows as $row) {
            $date = (string) $row->date;
            $existing = $calendar[(int) $row->employee_id][$date] ?? null;

            // Two approved halves of one day (e.g. two types) make a full day.
            $portion = $existing !== null ? DayPortion::Full : DayPortion::from((string) $row->portion);

            $calendar[(int) $row->employee_id][$date] = new LeaveDay(
                $date,
                $portion,
                (string) $row->name,
                (string) $row->code,
                (bool) $row->is_paid,
                (int) $row->request_id,
            );
        }

        return $calendar;
    }
}
