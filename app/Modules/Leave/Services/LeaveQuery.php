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
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

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
        $query = LeaveRequest::query()->with(['employee', 'type', 'requester', 'decider', 'attachments.uploader']);

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
     * Approved leave taken in a range, per employee of the viewer's (Reports):
     * the total and the split per leave type, in half days. Only the counted
     * days of a request are included (holidays and rest days inside it are
     * free), clipped to the range.
     *
     * Filters: from, to (required), department_id, leave_type_id.
     *
     * @param  array<string, mixed>  $filters
     * @param  array{0: string, 1: 'asc'|'desc'}  $sort  'name' or 'half_days'
     * @return LengthAwarePaginator<int, array{employee_id: int, half_days: int, types: array<int, int>}>
     */
    public function takenPerEmployee(array $filters, ?string $search, array $sort, int $perPage, User $viewer, int $page = 1): LengthAwarePaginator
    {
        $base = fn () => $this->takenQuery($filters, $search, $viewer);
        [$column, $direction] = $sort;

        $query = $base()
            ->select('leave_request_days.employee_id')
            ->selectRaw($this->halves().' as half_days')
            ->groupBy('leave_request_days.employee_id');

        if ($column === 'name') {
            $name = fn (string $field) => Employee::withTrashed()->select($field)->whereColumn('employees.id', 'leave_request_days.employee_id');
            $query->orderBy($name('last_name'), $direction)->orderBy($name('first_name'), $direction);
        } else {
            $query->orderBy('half_days', $direction);
        }

        $paginator = $query->orderBy('leave_request_days.employee_id')->paginate($perPage, ['*'], 'page', $page);
        $ids = array_map(fn (object $row) => (int) $row->employee_id, $paginator->items());

        $types = [];
        foreach ($base()->whereIn('leave_request_days.employee_id', $ids)
            ->select(['leave_request_days.employee_id', 'leave_requests.leave_type_id'])
            ->selectRaw($this->halves().' as half_days')
            ->groupBy('leave_request_days.employee_id', 'leave_requests.leave_type_id')
            ->get() as $row) {
            $types[(int) $row->employee_id][(int) $row->leave_type_id] = (int) $row->half_days;
        }

        $paginator->setCollection($paginator->getCollection()->map(fn (object $row): array => [
            'employee_id' => (int) $row->employee_id,
            'half_days' => (int) $row->half_days,
            'types' => $types[(int) $row->employee_id] ?? [],
        ]));

        /** @var LengthAwarePaginator<int, array{employee_id: int, half_days: int, types: array<int, int>}> $paginator */
        return $paginator;
    }

    /**
     * Approved half days taken in a range by the viewer's employees, in total.
     *
     * @param  array<string, mixed>  $filters  from, to, department_id
     */
    public function takenTotal(array $filters, User $viewer): int
    {
        return (int) $this->takenQuery($filters, null, $viewer)->selectRaw($this->halves().' as half_days')->value('half_days');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function takenQuery(array $filters, ?string $search, User $viewer): QueryBuilder
    {
        $query = DB::table('leave_request_days')
            ->join('leave_requests', 'leave_requests.id', '=', 'leave_request_days.leave_request_id')
            ->where('leave_requests.status', LeaveRequestStatus::Approved->value)
            ->where('leave_request_days.date', '>=', (string) $filters['from'])
            ->where('leave_request_days.date', '<=', (string) $filters['to']);

        if (isset($filters['leave_type_id'])) {
            $query->where('leave_requests.leave_type_id', (int) $filters['leave_type_id']);
        }

        $employees = $this->employeeScope($viewer, $search, isset($filters['department_id']) ? (int) $filters['department_id'] : null);
        if ($employees !== null) {
            $query->whereIn('leave_request_days.employee_id', $employees);
        }

        return $query;
    }

    private function halves(): string
    {
        return "sum(case leave_request_days.portion when 'full' then 2 else 1 end)";
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     */
    private function scope(Builder $query, User $viewer, ?string $search, ?int $departmentId): void
    {
        $employees = $this->employeeScope($viewer, $search, $departmentId);

        if ($employees !== null) {
            $query->whereIn('employee_id', $employees);
        }
    }

    /**
     * Ids of the employees the viewer may see (and matching the search and
     * department), or null when that is everyone.
     *
     * @return Builder<Employee>|null
     */
    private function employeeScope(User $viewer, ?string $search, ?int $departmentId): ?Builder
    {
        if ($search === null && $departmentId === null && $this->visibility->seesEveryone($viewer)) {
            return null;
        }

        $employees = Employee::withTrashed()->select('employees.id');
        $this->visibility->apply($employees, $viewer);
        Search::apply($employees, ['employee_code', 'first_name', 'last_name'], $search);

        if ($departmentId !== null) {
            $ids = $this->tree->withDescendants([$departmentId]);
            $employees->whereHas('currentAssignment', fn (Builder $a) => $a->whereIn('department_id', $ids));
        }

        return $employees;
    }
}
