<?php

declare(strict_types=1);

namespace App\Modules\Reports\Reports;

use App\Modules\Attendance\Services\AttendanceQuery;
use App\Modules\Identity\Models\User;
use App\Modules\Reports\Enums\ReportKind;
use App\Modules\Reports\Support\EmployeeLookup;
use App\Modules\Reports\Support\Kpis;
use App\Modules\Reports\Support\ReportPage;
use App\Modules\Reports\Support\ReportParams;

/**
 * One row per employee with calculated attendance in the range: days by
 * outcome, minutes and rates. Live over attendance days (any viewer).
 */
final class AttendanceSummaryReport implements Report
{
    /** Public sort key => aggregate alias. */
    private const SORTS = [
        'name' => 'name',
        'absent_days' => 'absent',
        'late_days' => 'late_days',
        'late_minutes' => 'late_minutes',
        'early_leave_minutes' => 'early_leave_minutes',
        'worked_minutes' => 'worked_minutes',
    ];

    public function __construct(
        private readonly AttendanceQuery $attendance,
    ) {}

    public function kind(): ReportKind
    {
        return ReportKind::AttendanceSummary;
    }

    public function rules(): array
    {
        return [];
    }

    public function monthly(): bool
    {
        return false;
    }

    public function sortable(): array
    {
        return array_keys(self::SORTS);
    }

    public function defaultSort(): string
    {
        return 'name';
    }

    public function page(ReportParams $params, User $viewer, int $perPage, int $page): ReportPage
    {
        [$key, $direction] = $params->sortOr($this->defaultSort());
        $paginator = $this->attendance->aggregatePerEmployee(
            $params->filters(), $params->search, [self::SORTS[$key], $direction], $perPage, $viewer, $page,
        );

        /** @var list<array<string, int|string|null>> $items */
        $items = $paginator->items();
        $employees = EmployeeLookup::employees(array_map(fn (array $row) => (int) $row['employee_id'], $items));

        return ReportPage::of($paginator, array_map(fn (array $row): array => [
            'employee' => $employees[(int) $row['employee_id']] ?? null,
            ...Kpis::from($row),
        ], $items));
    }

    public function columns(ReportParams $params, ReportPage $firstPage): array
    {
        return [
            'Employee code' => fn (array $r) => $r['employee']['employee_code'] ?? null,
            'Employee' => fn (array $r) => $r['employee']['full_name'] ?? null,
            'Working days' => fn (array $r) => $r['working_days'],
            'Attended days' => fn (array $r) => $r['attended_days'],
            'Absent days' => fn (array $r) => $r['absent_days'],
            'Late days' => fn (array $r) => $r['late_days'],
            'Early leave days' => fn (array $r) => $r['early_leave_days'],
            'Incomplete days' => fn (array $r) => $r['incomplete_days'],
            'Leave days' => fn (array $r) => $r['leave_days'],
            'Half-day leave days' => fn (array $r) => $r['half_day_leave_days'],
            'Holidays' => fn (array $r) => $r['holiday_days'],
            'Scheduled minutes' => fn (array $r) => $r['scheduled_minutes'],
            'Worked minutes' => fn (array $r) => $r['worked_minutes'],
            'Late minutes' => fn (array $r) => $r['late_minutes'],
            'Early leave minutes' => fn (array $r) => $r['early_leave_minutes'],
            'Attendance rate (%)' => fn (array $r) => $r['attendance_rate'],
            'Punctuality (%)' => fn (array $r) => $r['punctuality_rate'],
        ];
    }
}
