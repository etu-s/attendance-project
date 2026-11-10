<?php

declare(strict_types=1);

namespace App\Modules\Reports\Reports;

use App\Modules\Identity\Models\User;
use App\Modules\Overtime\Services\OvertimeQuery;
use App\Modules\Reports\Enums\ReportKind;
use App\Modules\Reports\Support\EmployeeLookup;
use App\Modules\Reports\Support\ReportPage;
use App\Modules\Reports\Support\ReportParams;

/**
 * Overtime per employee over the range (the Overtime module's summary):
 * approved and weighted minutes per kind of time, and what is pending.
 * Location and group filters do not apply; the department is the current one.
 */
final class OvertimeReport implements Report
{
    public function __construct(
        private readonly OvertimeQuery $overtime,
    ) {}

    public function kind(): ReportKind
    {
        return ReportKind::Overtime;
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
        return ['approved_weighted_minutes'];
    }

    public function defaultSort(): string
    {
        return '-approved_weighted_minutes';
    }

    public function page(ReportParams $params, User $viewer, int $perPage, int $page): ReportPage
    {
        $filters = array_intersect_key($params->filters(), array_flip(['from', 'to', 'department_id']));
        $paginator = $this->overtime->summary($filters, $params->search, $perPage, $viewer, $page);

        /** @var list<object> $items */
        $items = $paginator->items();
        $employees = EmployeeLookup::employees(array_map(fn (object $row) => (int) $row->employee_id, $items));

        return ReportPage::of($paginator, array_map(fn (object $row): array => [
            'employee' => $employees[(int) $row->employee_id] ?? null,
            'approved_minutes' => (int) $row->approved_minutes,
            'approved_weighted_minutes' => (int) $row->approved_weighted_minutes,
            'approved_workday_minutes' => (int) $row->approved_workday_minutes,
            'approved_rest_day_minutes' => (int) $row->approved_rest_day_minutes,
            'approved_holiday_minutes' => (int) $row->approved_holiday_minutes,
            'pending_minutes' => (int) $row->pending_minutes,
            'pending_count' => (int) $row->pending_count,
        ], $items));
    }

    public function columns(ReportParams $params, ReportPage $firstPage): array
    {
        return [
            'Employee code' => fn (array $r) => $r['employee']['employee_code'] ?? null,
            'Employee' => fn (array $r) => $r['employee']['full_name'] ?? null,
            'Approved minutes' => fn (array $r) => $r['approved_minutes'],
            'Weighted minutes' => fn (array $r) => $r['approved_weighted_minutes'],
            'Working-day minutes' => fn (array $r) => $r['approved_workday_minutes'],
            'Rest-day minutes' => fn (array $r) => $r['approved_rest_day_minutes'],
            'Holiday minutes' => fn (array $r) => $r['approved_holiday_minutes'],
            'Pending minutes' => fn (array $r) => $r['pending_minutes'],
            'Pending records' => fn (array $r) => $r['pending_count'],
        ];
    }
}
