<?php

declare(strict_types=1);

namespace App\Modules\Overtime\Services;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Support\Search;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Services\DepartmentTree;
use App\Modules\Organization\Services\EmployeeVisibility;
use App\Modules\Overtime\Enums\OvertimeStatus;
use App\Modules\Overtime\Models\OvertimeRecord;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Overtime of the employees the viewer may see (EmployeeVisibility).
 */
final class OvertimeQuery
{
    public function __construct(
        private readonly EmployeeVisibility $visibility,
        private readonly DepartmentTree $tree,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{0: string, 1: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, OvertimeRecord>
     */
    public function paginate(array $filters, ?string $search, array $sort, int $perPage, User $viewer): LengthAwarePaginator
    {
        $query = $this->filtered($filters, $search, $viewer)->with(['employee', 'decider', 'creator']);
        [$column, $direction] = $sort;

        return $query->orderBy($column, $direction)->orderBy('id', $direction)->paginate($perPage)->withQueryString();
    }

    /**
     * Totals per employee over a range: approved and weighted minutes per
     * kind of time, and what is still pending.
     *
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, OvertimeRecord>
     */
    public function summary(array $filters, ?string $search, int $perPage, User $viewer, ?int $page = null): LengthAwarePaginator
    {
        unset($filters['status']);
        $approved = OvertimeStatus::Approved->value;
        $pending = OvertimeStatus::Pending->value;

        // Approved minutes are split across kinds in proportion to the calculated breakdown.
        $share = fn (string $column) => "sum(case when status = '{$approved}' and calculated_minutes > 0 then {$column} * approved_minutes / calculated_minutes else 0 end)";

        return $this->filtered($filters, $search, $viewer)
            ->toBase()
            ->selectRaw('employee_id')
            ->selectRaw("sum(case when status = '{$approved}' then approved_minutes else 0 end) as approved_minutes")
            ->selectRaw("sum(case when status = '{$approved}' then approved_weighted_minutes else 0 end) as approved_weighted_minutes")
            ->selectRaw($share('(before_minutes + after_minutes)').' as approved_workday_minutes')
            ->selectRaw($share('rest_day_minutes').' as approved_rest_day_minutes')
            ->selectRaw($share('holiday_minutes').' as approved_holiday_minutes')
            ->selectRaw("sum(case when status = '{$pending}' then calculated_minutes else 0 end) as pending_minutes")
            ->selectRaw("sum(case when status = '{$pending}' then 1 else 0 end) as pending_count")
            ->groupBy('employee_id')
            ->orderByDesc('approved_weighted_minutes')
            ->orderBy('employee_id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();
    }

    /**
     * Totals over a range for the viewer's employees (Reports dashboard).
     *
     * @param  array<string, mixed>  $filters  from, to, department_id
     * @return array{approved_minutes: int, approved_weighted_minutes: int, pending_minutes: int, pending_count: int}
     */
    public function totals(array $filters, User $viewer): array
    {
        unset($filters['status']);
        $approved = OvertimeStatus::Approved->value;
        $pending = OvertimeStatus::Pending->value;

        $row = $this->filtered($filters, null, $viewer)
            ->toBase()
            ->selectRaw("sum(case when status = '{$approved}' then approved_minutes else 0 end) as approved_minutes")
            ->selectRaw("sum(case when status = '{$approved}' then approved_weighted_minutes else 0 end) as approved_weighted_minutes")
            ->selectRaw("sum(case when status = '{$pending}' then calculated_minutes else 0 end) as pending_minutes")
            ->selectRaw("sum(case when status = '{$pending}' then 1 else 0 end) as pending_count")
            ->first();

        return [
            'approved_minutes' => (int) ($row->approved_minutes ?? 0),
            'approved_weighted_minutes' => (int) ($row->approved_weighted_minutes ?? 0),
            'pending_minutes' => (int) ($row->pending_minutes ?? 0),
            'pending_count' => (int) ($row->pending_count ?? 0),
        ];
    }

    /**
     * @return Collection<int, OvertimeRecord>
     */
    public function forEmployee(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return OvertimeRecord::query()
            ->with(['employee', 'decider', 'creator'])
            ->where('employee_id', $employee->id)
            ->where('work_date', '>=', $from->format(LocalDate::FORMAT))
            ->where('work_date', '<=', $to->format(LocalDate::FORMAT))
            ->orderBy('work_date')
            ->orderBy('source')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<OvertimeRecord>
     */
    private function filtered(array $filters, ?string $search, User $viewer): Builder
    {
        $query = OvertimeRecord::query();

        foreach (['status', 'source', 'employee_id'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }
        if (isset($filters['from'])) {
            $query->where('work_date', '>=', (string) $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('work_date', '<=', (string) $filters['to']);
        }
        if (! empty($filters['changed'])) {
            $query->where('changed_after_decision', true);
        }

        $departmentId = isset($filters['department_id']) ? (int) $filters['department_id'] : null;

        if ($search !== null || $departmentId !== null || ! $this->visibility->seesEveryone($viewer)) {
            $employees = Employee::withTrashed()->select('employees.id');
            $this->visibility->apply($employees, $viewer);
            Search::apply($employees, ['employee_code', 'first_name', 'last_name'], $search);
            if ($departmentId !== null) {
                $ids = $this->tree->withDescendants([$departmentId]);
                $employees->whereHas('currentAssignment', fn (Builder $a) => $a->whereIn('department_id', $ids));
            }
            $query->whereIn('employee_id', $employees);
        }

        return $query;
    }
}
