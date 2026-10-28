<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Support\Search;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Services\DepartmentTree;
use App\Modules\Organization\Services\EmployeeVisibility;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Reading calculated attendance, always scoped to the employees the viewer
 * may see (EmployeeVisibility). Department filters use the department
 * recorded on each day, so history stays where it happened.
 */
final class AttendanceQuery
{
    public function __construct(
        private readonly EmployeeVisibility $visibility,
        private readonly DepartmentTree $tree,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{0: string, 1: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, AttendanceDay>
     */
    public function paginate(array $filters, ?string $search, array $sort, int $perPage, User $viewer): LengthAwarePaginator
    {
        $query = $this->filtered($filters, $search, $viewer)->with('employee');
        [$column, $direction] = $sort;

        return $query->orderBy($column, $direction)->orderBy('employee_id')->paginate($perPage)->withQueryString();
    }

    /**
     * Counts per status on one date for the viewer's employees.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int> status => count (every status present)
     */
    public function summary(CarbonImmutable $date, array $filters, User $viewer): array
    {
        $filters['date'] = $date->format(LocalDate::FORMAT);
        unset($filters['status']);

        $counts = $this->filtered($filters, null, $viewer)
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $summary = [];
        foreach (AttendanceStatus::cases() as $status) {
            $summary[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $summary;
    }

    /**
     * Every calculated day of one employee in a range (authorization is the caller's).
     *
     * @return Collection<int, AttendanceDay>
     */
    public function forEmployee(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return AttendanceDay::query()
            ->where('employee_id', $employee->id)
            ->where('work_date', '>=', $from->format(LocalDate::FORMAT))
            ->where('work_date', '<=', $to->format(LocalDate::FORMAT))
            ->orderBy('work_date')
            ->get();
    }

    /**
     * Calculated days with their periods for many employees (no visibility
     * scoping: for modules computing from attendance, such as overtime).
     *
     * @param  list<int>  $employeeIds
     * @param  list<string>|null  $dates  only these work dates, or every date in [from, to]
     * @return Collection<int, AttendanceDay>
     */
    public function withPeriods(array $employeeIds, CarbonImmutable $from, CarbonImmutable $to, ?array $dates = null): Collection
    {
        return AttendanceDay::query()
            ->with('periods')
            ->whereIn('employee_id', $employeeIds)
            ->where('work_date', '>=', $from->format(LocalDate::FORMAT))
            ->where('work_date', '<=', $to->format(LocalDate::FORMAT))
            ->when($dates !== null, fn (Builder $q) => $q->whereIn('work_date', $dates))
            ->orderBy('employee_id')
            ->orderBy('work_date')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<AttendanceDay>
     */
    private function filtered(array $filters, ?string $search, User $viewer): Builder
    {
        $query = AttendanceDay::query();

        if (isset($filters['date'])) {
            $query->where('work_date', (string) $filters['date']);
        }
        if (isset($filters['from'])) {
            $query->where('work_date', '>=', (string) $filters['from']);
        }
        if (isset($filters['to'])) {
            $query->where('work_date', '<=', (string) $filters['to']);
        }
        if (isset($filters['status'])) {
            $query->whereIn('status', (array) $filters['status']);
        }
        if (isset($filters['employee_id'])) {
            $query->where('employee_id', (int) $filters['employee_id']);
        }
        if (isset($filters['department_id'])) {
            $query->whereIn('department_id', $this->tree->withDescendants([(int) $filters['department_id']]));
        }

        $groupId = isset($filters['group_id']) ? (int) $filters['group_id'] : null;

        if ($search !== null || $groupId !== null || ! $this->visibility->seesEveryone($viewer)) {
            $employees = Employee::withTrashed()->select('employees.id');
            $this->visibility->apply($employees, $viewer);
            Search::apply($employees, ['employee_code', 'first_name', 'last_name'], $search);
            if ($groupId !== null) {
                $employees->whereHas('currentMemberships', fn (Builder $m) => $m->where('group_id', $groupId));
            }
            $query->whereIn('employee_id', $employees);
        }

        return $query;
    }
}
