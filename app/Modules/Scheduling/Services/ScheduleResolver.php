<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Services\SettingsService;
use App\Modules\Organization\Enums\EmployeeStatus;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\EmployeeAssignment;
use App\Modules\Organization\Models\GroupMembership;
use App\Modules\Organization\Services\AssignmentService;
use App\Modules\Organization\Services\DepartmentTree;
use App\Modules\Organization\Services\MembershipService;
use App\Modules\Scheduling\Enums\DayStatus;
use App\Modules\Scheduling\Enums\ScheduleTarget;
use App\Modules\Scheduling\Models\Holiday;
use App\Modules\Scheduling\Models\ScheduleAssignment;
use App\Modules\Scheduling\Models\TemporarySchedule;
use App\Modules\Scheduling\Support\ResolvedDay;
use App\Modules\Timetable\Models\Shift;
use App\Modules\Timetable\Services\ShiftCalendar;
use App\Modules\Timetable\Support\ScheduledPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * THE answer to "what is this employee scheduled to do on date D". Attendance
 * (Module 5) must use this; nothing else should combine schedule levels.
 *
 * Precedence, highest first:
 *   1. temporary schedule (a shift, or a day off) — also beats holidays
 *   2. employee schedule
 *   3. group schedule — among the employee's groups on D that HAVE a
 *      schedule, the lowest priority number wins
 *   4. department schedule — the employee's department on D, else its
 *      nearest ancestor with one
 *   5. organization default
 *
 * Department, groups and location are read as of each date from the dated
 * Organization history, so a mid-range transfer switches schedules on the
 * transfer date. The timezone of each date is the location's (if set) or the
 * workspace's. Everything for the range is loaded up front: query count does
 * not grow with the number of days or employees.
 */
final class ScheduleResolver
{
    public const MAX_DAYS = 366;

    /** @var array<int, Shift> */
    private array $shifts = [];

    /** @var array<string, array<string, list<ScheduledPeriod>>> */
    private array $expansions = [];

    public function __construct(
        private readonly AssignmentService $assignments,
        private readonly MembershipService $memberships,
        private readonly DepartmentTree $tree,
        private readonly HolidayService $holidays,
        private readonly ShiftCalendar $calendar,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array<string, ResolvedDay> date => day, every date in range
     */
    public function forEmployee(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return $this->forEmployees(collect([$employee]), $from, $to)[$employee->id];
    }

    /**
     * @param  Collection<int, Employee>  $employees
     * @return array<int, array<string, ResolvedDay>> employee id => date => day
     */
    public function forEmployees(Collection $employees, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = LocalDate::parse($from->format(LocalDate::FORMAT));
        $to = LocalDate::parse($to->format(LocalDate::FORMAT));

        if ($to->lessThan($from) || $from->diffInDays($to) >= self::MAX_DAYS) {
            throw new InvalidArgumentException('Resolve between 1 and '.self::MAX_DAYS.' days.');
        }

        $this->shifts = [];
        $this->expansions = [];

        $ids = $employees->pluck('id')->map(fn ($id) => (int) $id)->all();
        $orgAssignments = $this->assignments->forEmployees($ids, $from, $to);
        $memberships = $this->memberships->forEmployees($ids, $from, $to);
        $chains = $this->tree->ancestorChains();
        $departmentNames = Department::query()->pluck('name', 'id')->all();
        $schedules = $this->scheduleIndex($from, $to);
        $temporaries = TemporarySchedule::query()
            ->with('shift')
            ->whereIn('employee_id', $ids)
            ->where('date_from', '<=', $to->format(LocalDate::FORMAT))
            ->where('date_to', '>=', $from->format(LocalDate::FORMAT))
            ->get()
            ->groupBy('employee_id');
        $holidays = $this->holidays->occurrences($from, $to);
        $workspaceTz = (string) $this->settings->get('general.timezone');

        $result = [];
        foreach ($employees as $employee) {
            for ($date = $from; $date->lessThanOrEqualTo($to); $date = $date->addDay()) {
                $key = $date->format(LocalDate::FORMAT);
                $result[$employee->id][$key] = $this->resolveDay(
                    $employee,
                    $date,
                    $from,
                    $to,
                    $orgAssignments->get($employee->id, collect()),
                    $memberships->get($employee->id, collect()),
                    $temporaries->get($employee->id, collect()),
                    $schedules,
                    $chains,
                    $departmentNames,
                    $holidays[$key] ?? [],
                    $workspaceTz,
                );
            }
        }

        return $result;
    }

    /**
     * @param  Collection<int, EmployeeAssignment>  $orgAssignments
     * @param  Collection<int, GroupMembership>  $memberships
     * @param  Collection<int, TemporarySchedule>  $temporaries
     * @param  array<string, list<ScheduleAssignment>>  $schedules
     * @param  array<int, list<int>>  $chains
     * @param  array<int, string>  $departmentNames
     * @param  list<Holiday>  $holidays
     */
    private function resolveDay(
        Employee $employee,
        CarbonImmutable $date,
        CarbonImmutable $from,
        CarbonImmutable $to,
        Collection $orgAssignments,
        Collection $memberships,
        Collection $temporaries,
        array $schedules,
        array $chains,
        array $departmentNames,
        array $holidays,
        string $workspaceTz,
    ): ResolvedDay {
        $key = $date->format(LocalDate::FORMAT);
        $placement = $orgAssignments->first(fn (EmployeeAssignment $a) => $a->covers($date));
        $timezone = $placement?->location?->timezone ?? $workspaceTz;

        $terminatedBefore = $employee->status === EmployeeStatus::Terminated
            && $employee->termination_date !== null
            && $date->greaterThan($employee->termination_date);

        if ($date->lessThan($employee->hire_date) || $terminatedBefore) {
            return new ResolvedDay($key, DayStatus::NotEmployed, null, null, null, null, [], $timezone);
        }

        // 1. Temporary schedule (beats holidays).
        $temporary = $temporaries->first(fn (TemporarySchedule $t) => $t->covers($date));
        if ($temporary !== null) {
            if ($temporary->isDayOff()) {
                return new ResolvedDay($key, DayStatus::DayOff, 'temporary', $temporary->id, $temporary->reason, null, [], $timezone);
            }

            /** @var Shift $shift */
            $shift = $temporary->shift;
            $periods = $this->periods($shift, $temporary->anchor_date ?? $temporary->date_from, $from, $to, $timezone)[$key] ?? [];

            return new ResolvedDay($key, $periods === [] ? DayStatus::Rest : DayStatus::Working, 'temporary', $temporary->id, $temporary->reason, $shift, $periods, $timezone);
        }

        // 2–5. Standing schedules.
        [$assignment, $source, $sourceId, $sourceName, $inheritedFrom] = $this->standing($employee, $date, $placement, $memberships, $schedules, $chains, $departmentNames);

        if ($assignment === null) {
            return new ResolvedDay($key, DayStatus::Unscheduled, null, null, null, null, [], $timezone);
        }

        $shift = $assignment->shift;
        $periods = $this->periods($shift, $assignment->anchor_date, $from, $to, $timezone)[$key] ?? [];

        $locationId = $placement?->location_id;
        $holiday = collect($holidays)->first(fn (Holiday $h) => $h->location_id === null || $h->location_id === $locationId);

        $status = match (true) {
            $periods === [] => DayStatus::Rest,
            $holiday !== null => DayStatus::Holiday,
            default => DayStatus::Working,
        };

        return new ResolvedDay($key, $status, $source, $sourceId, $sourceName, $shift, $periods, $timezone, $holiday?->name, $inheritedFrom);
    }

    /**
     * @param  Collection<int, GroupMembership>  $memberships
     * @param  array<string, list<ScheduleAssignment>>  $schedules
     * @param  array<int, list<int>>  $chains
     * @param  array<int, string>  $departmentNames
     * @return array{0: ScheduleAssignment|null, 1: string|null, 2: int|null, 3: string|null, 4: string|null}
     */
    private function standing(
        Employee $employee,
        CarbonImmutable $date,
        ?EmployeeAssignment $placement,
        Collection $memberships,
        array $schedules,
        array $chains,
        array $departmentNames,
    ): array {
        if ($found = $this->covering($schedules, ScheduleTarget::Employee, $employee->id, $date)) {
            return [$found, 'employee', $employee->id, $employee->fullName(), null];
        }

        $groups = $memberships
            ->filter(fn (GroupMembership $m) => $m->effective_from->lessThanOrEqualTo($date) && ($m->effective_to === null || $m->effective_to->greaterThanOrEqualTo($date)))
            ->sortBy(fn (GroupMembership $m) => $m->group->priority);

        foreach ($groups as $membership) {
            if ($found = $this->covering($schedules, ScheduleTarget::Group, $membership->group_id, $date)) {
                return [$found, 'group', $membership->group_id, $membership->group->name, null];
            }
        }

        if ($placement !== null) {
            foreach ($chains[$placement->department_id] ?? [$placement->department_id] as $departmentId) {
                if ($found = $this->covering($schedules, ScheduleTarget::Department, $departmentId, $date)) {
                    $inherited = $departmentId === $placement->department_id ? null : ($departmentNames[$departmentId] ?? null);

                    return [$found, 'department', $departmentId, $departmentNames[$departmentId] ?? null, $inherited];
                }
            }
        }

        if ($found = $this->covering($schedules, ScheduleTarget::Organization, null, $date)) {
            return [$found, 'organization', null, 'Organization default', null];
        }

        return [null, null, null, null, null];
    }

    /**
     * @param  array<string, list<ScheduleAssignment>>  $schedules
     */
    private function covering(array $schedules, ScheduleTarget $type, ?int $id, CarbonImmutable $date): ?ScheduleAssignment
    {
        foreach ($schedules[$type->value.':'.($id ?? '')] ?? [] as $assignment) {
            if ($assignment->covers($date)) {
                return $assignment;
            }
        }

        return null;
    }

    /**
     * @return array<string, list<ScheduleAssignment>> target key => periods
     */
    private function scheduleIndex(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $index = [];

        $rows = ScheduleAssignment::query()
            ->with('shift')
            ->where('effective_from', '<=', $to->format(LocalDate::FORMAT))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from->format(LocalDate::FORMAT)))
            ->orderBy('effective_from')
            ->get();

        foreach ($rows as $row) {
            $index[$row->targetKey()][] = $row;
        }

        return $index;
    }

    /**
     * The shift's periods over the whole range, expanded once per
     * (shift, anchor, timezone) and reused for every employee and day.
     *
     * @return array<string, list<ScheduledPeriod>>
     */
    private function periods(Shift $shift, CarbonImmutable $anchor, CarbonImmutable $from, CarbonImmutable $to, string $timezone): array
    {
        $shift = $this->shifts[$shift->id] ??= $shift;
        $key = $shift->id.'|'.$anchor->format(LocalDate::FORMAT).'|'.$timezone;

        return $this->expansions[$key] ??= $this->calendar->expand($shift, $anchor, $from, $to, $timezone);
    }
}
