<?php

declare(strict_types=1);

namespace App\Modules\Reports\Reports;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Services\AttendanceQuery;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Identity\Models\User;
use App\Modules\Reports\Enums\ReportKind;
use App\Modules\Reports\Support\EmployeeLookup;
use App\Modules\Reports\Support\Kpis;
use App\Modules\Reports\Support\ReportPage;
use App\Modules\Reports\Support\ReportParams;

/**
 * The monthly register: employees × days of one month, a status code per
 * cell, plus the month's totals per employee.
 */
final class RegisterReport implements Report
{
    /** Short codes for the grid and the export (legend in meta). */
    public const CODES = [
        'present' => 'P',
        'late' => 'L',
        'early_leave' => 'E',
        'late_early' => 'LE',
        'incomplete' => 'I',
        'absent' => 'A',
        'in_progress' => 'W',
        'on_leave' => 'LV',
        'holiday' => 'H',
        'rest' => 'R',
        'day_off' => 'O',
        'unscheduled' => 'U',
        'not_employed' => '',
    ];

    public function __construct(
        private readonly AttendanceQuery $attendance,
    ) {}

    public function kind(): ReportKind
    {
        return ReportKind::Register;
    }

    public function rules(): array
    {
        return [];
    }

    public function monthly(): bool
    {
        return true;
    }

    public function sortable(): array
    {
        return ['name'];
    }

    public function defaultSort(): string
    {
        return 'name';
    }

    public function page(ReportParams $params, User $viewer, int $perPage, int $page): ReportPage
    {
        [, $direction] = $params->sortOr($this->defaultSort());
        $paginator = $this->attendance->aggregatePerEmployee(
            $params->filters(), $params->search, ['name', $direction], $perPage, $viewer, $page,
        );

        /** @var list<array<string, int|string|null>> $items */
        $items = $paginator->items();
        $ids = array_map(fn (array $row) => (int) $row['employee_id'], $items);
        $employees = EmployeeLookup::employees($ids);

        $cells = [];
        foreach ($this->attendance->daysFor($ids, $params->from, $params->to) as $day) {
            /** @var AttendanceDay $day */
            $cells[$day->employee_id][$day->work_date->format(LocalDate::FORMAT)] = [
                'status' => $day->status->value,
                'half_day_leave' => in_array('half_day_leave', $day->flags ?? [], true),
                'late_minutes' => $day->late_minutes,
                'early_leave_minutes' => $day->early_leave_minutes,
                'worked_minutes' => $day->worked_minutes,
            ];
        }

        return ReportPage::of($paginator, array_map(fn (array $row): array => [
            'employee' => $employees[(int) $row['employee_id']] ?? null,
            'days' => (object) ($cells[(int) $row['employee_id']] ?? []),
            'totals' => Kpis::from($row),
        ], $items), [
            'month' => $params->from->format('Y-m'),
            'dates' => $this->dates($params),
            'codes' => self::CODES,
        ]);
    }

    public function columns(ReportParams $params, ReportPage $firstPage): array
    {
        $columns = [
            'Employee code' => fn (array $r) => $r['employee']['employee_code'] ?? null,
            'Employee' => fn (array $r) => $r['employee']['full_name'] ?? null,
        ];

        foreach ($this->dates($params) as $date) {
            $columns[substr($date, 8, 2)] = function (array $r) use ($date): string {
                $cell = ((array) $r['days'])[$date] ?? null;
                if ($cell === null) {
                    return '';
                }

                return self::CODES[$cell['status']].($cell['half_day_leave'] && $cell['status'] !== AttendanceStatus::OnLeave->value ? '½' : '');
            };
        }

        return $columns + [
            'Attended' => fn (array $r) => $r['totals']['attended_days'],
            'Absent' => fn (array $r) => $r['totals']['absent_days'],
            'Late' => fn (array $r) => $r['totals']['late_days'],
            'Leave' => fn (array $r) => $r['totals']['leave_days'],
            'Worked minutes' => fn (array $r) => $r['totals']['worked_minutes'],
        ];
    }

    /**
     * @return list<string>
     */
    private function dates(ReportParams $params): array
    {
        $dates = [];
        for ($date = $params->from; $date->lessThanOrEqualTo($params->to); $date = $date->addDay()) {
            $dates[] = $date->format(LocalDate::FORMAT);
        }

        return $dates;
    }
}
