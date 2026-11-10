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
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Reading calculated attendance, always scoped to the employees the viewer
 * may see (EmployeeVisibility). Department filters use the department
 * recorded on each day, so history stays where it happened.
 */
final class AttendanceQuery
{
    /**
     * Statuses of a working day on which the employee came to work (Reports'
     * "attended"). Absent is the other outcome of a working day; leave,
     * holidays and rest days are neither.
     */
    public const ATTENDED = [
        AttendanceStatus::Present,
        AttendanceStatus::Late,
        AttendanceStatus::EarlyLeave,
        AttendanceStatus::LateEarly,
        AttendanceStatus::Incomplete,
    ];

    public function __construct(
        private readonly EmployeeVisibility $visibility,
        private readonly DepartmentTree $tree,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{0: string, 1: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, AttendanceDay>
     */
    public function paginate(array $filters, ?string $search, array $sort, int $perPage, User $viewer, ?int $page = null): LengthAwarePaginator
    {
        $query = $this->filtered($filters, $search, $viewer)->with('employee');
        [$column, $direction] = $sort;

        return $query->orderBy($column, $direction)->orderBy('employee_id')->paginate($perPage, ['*'], 'page', $page)->withQueryString();
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
     * Aggregate columns shared by the live aggregates below and Reports'
     * daily rollup, so both always count the same way. Days outside
     * employment (not_employed) are never part of an aggregate.
     *
     *  - one count per status (present … unscheduled);
     *  - half_day_leave: days with half a day of approved leave;
     *  - late_days: attended days with any late minutes (punctuality);
     *  - scheduled_minutes: scheduled time of attended and absent days only;
     *  - worked / late / early-leave minutes of every day.
     *
     * @return array<string, string> alias => SQL expression (portable SQL)
     */
    public static function aggregateColumns(): array
    {
        $attended = "'".implode("', '", array_map(fn (AttendanceStatus $s) => $s->value, self::ATTENDED))."'";
        $absent = AttendanceStatus::Absent->value;

        $columns = ['days' => 'count(*)'];
        foreach (AttendanceStatus::cases() as $status) {
            if ($status !== AttendanceStatus::NotEmployed) {
                $columns[$status->value] = "sum(case when status = '{$status->value}' then 1 else 0 end)";
            }
        }

        return $columns + [
            'half_day_leave' => "sum(case when flags like '%\"half_day_leave\"%' then 1 else 0 end)",
            'late_days' => "sum(case when late_minutes > 0 and status in ({$attended}) then 1 else 0 end)",
            'scheduled_minutes' => "sum(case when status in ({$attended}, '{$absent}') then scheduled_minutes else 0 end)",
            'worked_minutes' => 'sum(worked_minutes)',
            'late_minutes' => 'sum(late_minutes)',
            'early_leave_minutes' => 'sum(early_leave_minutes)',
        ];
    }

    /**
     * Aggregates of every employee per (work date, department, location) on
     * the given dates, without visibility scoping: the source of Reports'
     * daily rollup. Department and location are the ones recorded on each day.
     *
     * @param  list<string>  $dates  Y-m-d
     * @return list<array<string, int|string|null>>
     */
    public function dailyStats(array $dates): array
    {
        if ($dates === []) {
            return [];
        }

        $query = DB::table('attendance_days')
            ->whereIn('work_date', $dates)
            ->where('status', '!=', AttendanceStatus::NotEmployed->value)
            ->select(['work_date', 'department_id', 'location_id'])
            ->groupBy('work_date', 'department_id', 'location_id')
            ->orderBy('work_date');
        $this->selectAggregates($query);

        return array_map(fn (object $row): array => $this->castAggregate((array) $row), $query->get()->all());
    }

    /**
     * First and last calculated work date, or null when there is none.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function dateBounds(): ?array
    {
        $row = DB::table('attendance_days')->selectRaw('min(work_date) as first, max(work_date) as last')->first();

        if ($row === null || $row->first === null) {
            return null;
        }

        return [LocalDate::parse(substr((string) $row->first, 0, 10)), LocalDate::parse(substr((string) $row->last, 0, 10))];
    }

    /**
     * Live aggregates of the viewer's employees (EmployeeVisibility), in total
     * or per work_date, department_id, location_id or employee_id.
     *
     * Filters: from, to, department_id (with sub-departments), location_id,
     * group_id, employee_id, plus a name/code search.
     *
     * @param  array<string, mixed>  $filters
     * @param  'work_date'|'department_id'|'location_id'|'employee_id'|null  $groupBy
     * @return list<array<string, int|string|null>>
     */
    public function aggregate(array $filters, ?string $search, User $viewer, ?string $groupBy = null): array
    {
        $query = $this->aggregateQuery($filters, $search, $viewer, $groupBy);

        if ($groupBy !== null) {
            $query->orderBy($groupBy);
        }

        return array_map(fn (object $row): array => $this->castAggregate((array) $row), $query->get()->all());
    }

    /**
     * Per-employee aggregates, paginated. Sort by an aggregate alias, or by
     * "name" (last name, first name of the employee).
     *
     * @param  array<string, mixed>  $filters
     * @param  array{0: string, 1: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, array<string, int|string|null>>
     */
    public function aggregatePerEmployee(array $filters, ?string $search, array $sort, int $perPage, User $viewer, int $page = 1): LengthAwarePaginator
    {
        [$column, $direction] = $sort;
        $query = $this->aggregateQuery($filters, $search, $viewer, 'employee_id');

        if ($column === 'name') {
            $name = fn (string $field) => Employee::withTrashed()->select($field)->whereColumn('employees.id', 'attendance_days.employee_id');
            $query->orderBy($name('last_name'), $direction)->orderBy($name('first_name'), $direction);
        } else {
            $query->orderBy($column, $direction);
        }

        $paginator = $query->orderBy('employee_id')->paginate($perPage, ['*'], 'page', $page);
        $paginator->setCollection($paginator->getCollection()->map(fn (object $row): array => $this->castAggregate((array) $row)));

        /** @var LengthAwarePaginator<int, array<string, int|string|null>> $paginator */
        return $paginator;
    }

    /**
     * Calculated days (without periods) of many employees in a range, for
     * grids such as the monthly register. Authorization is the caller's.
     *
     * @param  list<int>  $employeeIds
     * @return Collection<int, AttendanceDay>
     */
    public function daysFor(array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return AttendanceDay::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('work_date', '>=', $from->format(LocalDate::FORMAT))
            ->where('work_date', '<=', $to->format(LocalDate::FORMAT))
            ->orderBy('employee_id')
            ->orderBy('work_date')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function aggregateQuery(array $filters, ?string $search, User $viewer, ?string $groupBy): QueryBuilder
    {
        unset($filters['status'], $filters['date']);

        $query = $this->filtered($filters, $search, $viewer)
            ->where('status', '!=', AttendanceStatus::NotEmployed->value)
            ->toBase();
        $query->columns = null;

        if ($groupBy !== null) {
            $query->addSelect($groupBy)->groupBy($groupBy);
        }
        $this->selectAggregates($query);

        return $query;
    }

    private function selectAggregates(QueryBuilder $query): void
    {
        foreach (self::aggregateColumns() as $alias => $expression) {
            $query->selectRaw("{$expression} as {$alias}");
        }
    }

    /**
     * SQLite returns sums as int, or null over no rows.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, int|string|null>
     */
    private function castAggregate(array $row): array
    {
        foreach (array_keys(self::aggregateColumns()) as $alias) {
            $row[$alias] = (int) ($row[$alias] ?? 0);
        }
        foreach (['department_id', 'location_id', 'employee_id'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
        }
        if (array_key_exists('work_date', $row)) {
            $row['work_date'] = substr((string) $row['work_date'], 0, 10);
        }

        return $row;
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
        if (isset($filters['location_id'])) {
            $query->where('location_id', (int) $filters['location_id']);
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
