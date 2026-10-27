<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Support\Search;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Services\DepartmentTree;
use App\Modules\Organization\Services\EmployeeVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Leave requests of the employees the viewer may see (EmployeeVisibility);
 * employees see their own.
 */
final class LeaveQuery
{
    public function __construct(
        private readonly EmployeeVisibility $visibility,
        private readonly DepartmentTree $tree,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{0: string, 1: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, LeaveRequest>
     */
    public function paginate(array $filters, ?string $search, array $sort, int $perPage, User $viewer): LengthAwarePaginator
    {
        $query = LeaveRequest::query()->with(['employee', 'type', 'requester', 'decider']);

        foreach (['status', 'leave_type_id', 'employee_id'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }
        if (isset($filters['from'])) {
            $query->where('end_date', '>=', (string) $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('start_date', '<=', (string) $filters['to']);
        }
        if (! empty($filters['mine'])) {
            $query->whereIn('employee_id', Employee::withTrashed()->select('id')->where('user_id', $viewer->id));
        }

        $this->scope($query, $viewer, $search, isset($filters['department_id']) ? (int) $filters['department_id'] : null);

        [$column, $direction] = $sort;

        return $query->orderBy($column, $direction)->orderBy('id', $direction)->paginate($perPage)->withQueryString();
    }

    /**
     * Pending and approved leave overlapping a range (team calendar).
     *
     * @return Collection<int, LeaveRequest>
     */
    public function calendar(CarbonImmutable $from, CarbonImmutable $to, User $viewer, ?int $departmentId): Collection
    {
        $query = LeaveRequest::query()
            ->with(['employee', 'type', 'days'])
            ->whereIn('status', [LeaveRequestStatus::Pending->value, LeaveRequestStatus::Approved->value])
            ->where('end_date', '>=', $from->format(LocalDate::FORMAT))
            ->where('start_date', '<=', $to->format(LocalDate::FORMAT));

        $this->scope($query, $viewer, null, $departmentId);

        return $query->orderBy('start_date')->orderBy('id')->get();
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     */
    private function scope(Builder $query, User $viewer, ?string $search, ?int $departmentId): void
    {
        if ($search === null && $departmentId === null && $this->visibility->seesEveryone($viewer)) {
            return;
        }

        $employees = Employee::withTrashed()->select('employees.id');
        $this->visibility->apply($employees, $viewer);
        Search::apply($employees, ['employee_code', 'first_name', 'last_name'], $search);

        if ($departmentId !== null) {
            $ids = $this->tree->withDescendants([$departmentId]);
            $employees->whereHas('currentAssignment', fn (Builder $a) => $a->whereIn('department_id', $ids));
        }

        $query->whereIn('employee_id', $employees);
    }
}
