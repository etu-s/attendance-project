<?php

declare(strict_types=1);

namespace App\Modules\Reports\Reports;

use App\Modules\Identity\Models\User;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveQuery;
use App\Modules\Reports\Enums\ReportKind;
use App\Modules\Reports\Support\EmployeeLookup;
use App\Modules\Reports\Support\ReportPage;
use App\Modules\Reports\Support\ReportParams;

/**
 * Approved leave taken in the range, per employee, in total and per leave
 * type. Amounts are half days (1 = half a day), like the leave ledger.
 * Location and group filters do not apply (leave has no such placement).
 */
final class LeaveReport implements Report
{
    public function __construct(
        private readonly LeaveQuery $leave,
    ) {}

    public function kind(): ReportKind
    {
        return ReportKind::Leave;
    }

    public function rules(): array
    {
        return ['leave_type_id' => ['sometimes', 'nullable', 'integer', 'min:1']];
    }

    public function monthly(): bool
    {
        return false;
    }

    public function sortable(): array
    {
        return ['name', 'half_days'];
    }

    public function defaultSort(): string
    {
        return '-half_days';
    }

    public function page(ReportParams $params, User $viewer, int $perPage, int $page): ReportPage
    {
        $paginator = $this->leave->takenPerEmployee(
            [...$params->filters(), ...$params->extra],
            $params->search,
            $params->sortOr($this->defaultSort()),
            $perPage,
            $viewer,
            $page,
        );

        /** @var list<array{employee_id: int, half_days: int, types: array<int, int>}> $items */
        $items = $paginator->items();
        $employees = EmployeeLookup::employees(array_map(fn (array $row) => $row['employee_id'], $items));

        return ReportPage::of($paginator, array_map(fn (array $row): array => [
            'employee' => $employees[$row['employee_id']] ?? null,
            'half_days' => $row['half_days'],
            'types' => (object) $row['types'],
        ], $items), ['leave_types' => $this->types()]);
    }

    public function columns(ReportParams $params, ReportPage $firstPage): array
    {
        $columns = [
            'Employee code' => fn (array $r) => $r['employee']['employee_code'] ?? null,
            'Employee' => fn (array $r) => $r['employee']['full_name'] ?? null,
            'Total days' => fn (array $r) => $r['half_days'] / 2,
        ];

        foreach ($this->types() as $type) {
            $columns["{$type['name']} (days)"] = fn (array $r) => (((array) $r['types'])[$type['id']] ?? 0) / 2;
        }

        return $columns;
    }

    /**
     * @return list<array{id: int, name: string, code: string, color: string|null}>
     */
    private function types(): array
    {
        return LeaveType::query()->orderBy('name')->get(['id', 'name', 'code', 'color'])
            ->map(fn (LeaveType $t) => ['id' => $t->id, 'name' => $t->name, 'code' => $t->code, 'color' => $t->color])
            ->values()->all();
    }
}
