<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Organization\Enums\EmployeeStatus;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\EmployeeAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Effective-dated department/location history. The public question later
 * modules ask is on(): "where did this employee belong on date D?".
 *
 * Invariant: an employee's periods never overlap. A transfer on date D splits
 * the period covering D (it now ends on D-1) and the new period runs from D to
 * wherever the old one ended, so later history is left untouched. That makes
 * backdated transfers safe to insert.
 */
final class AssignmentService
{
    public function on(Employee $employee, CarbonImmutable $date): ?EmployeeAssignment
    {
        $day = $date->format(LocalDate::FORMAT);

        return $employee->assignments()
            ->with(['department', 'location'])
            ->where('effective_from', '<=', $day)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $day))
            ->first();
    }

    /**
     * @return Collection<int, EmployeeAssignment>
     */
    public function history(Employee $employee): Collection
    {
        return $employee->assignments()->with(['department', 'location'])->orderByDesc('effective_from')->get();
    }

    /**
     * Assignments (with location) of many employees overlapping a date range,
     * in one query; for range calculations such as schedule resolution.
     *
     * @param  list<int>  $employeeIds
     * @return \Illuminate\Support\Collection<int, Collection<int, EmployeeAssignment>> employee id => periods by start
     */
    public function forEmployees(array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): \Illuminate\Support\Collection
    {
        return EmployeeAssignment::query()
            ->with('location')
            ->whereIn('employee_id', $employeeIds)
            ->where('effective_from', '<=', $to->format(LocalDate::FORMAT))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from->format(LocalDate::FORMAT)))
            ->orderBy('effective_from')
            ->get()
            ->groupBy('employee_id');
    }

    /**
     * Opens an employee's first period (hire) or a new period after a gap (rehire).
     */
    public function start(Employee $employee, int $departmentId, ?int $locationId, CarbonImmutable $from): EmployeeAssignment
    {
        $day = $from->format(LocalDate::FORMAT);

        $conflict = $employee->assignments()
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $day))
            ->exists();

        if ($conflict) {
            throw new BusinessRuleException('The employee already has an assignment on or after this date.', 'assignment_overlap');
        }

        return EmployeeAssignment::query()->create([
            'employee_id' => $employee->id,
            'department_id' => $departmentId,
            'location_id' => $locationId,
            'effective_from' => $from,
            'effective_to' => null,
        ]);
    }

    public function transfer(Employee $employee, int $departmentId, ?int $locationId, CarbonImmutable $from): EmployeeAssignment
    {
        $this->assertWithinEmployment($employee, $from);

        $covering = $this->on($employee, $from)
            ?? throw new BusinessRuleException('The employee has no assignment on this date to transfer from.', 'no_assignment_on_date');

        if ($covering->department_id === $departmentId && $covering->location_id === $locationId) {
            throw new BusinessRuleException('The employee is already in this department and location on this date.', 'assignment_unchanged');
        }

        // Same start day: this is a correction of that period, not a new one.
        if ($covering->effective_from->equalTo($from)) {
            $covering->update(['department_id' => $departmentId, 'location_id' => $locationId]);

            return $covering->refresh();
        }

        $originalEnd = $covering->effective_to;
        $covering->update(['effective_to' => $from->subDay()]);

        return EmployeeAssignment::query()->create([
            'employee_id' => $employee->id,
            'department_id' => $departmentId,
            'location_id' => $locationId,
            'effective_from' => $from,
            'effective_to' => $originalEnd,
        ]);
    }

    /**
     * Ends the employee's assignment on $date (termination). Refuses when a
     * later period exists: terminating before a recorded transfer would erase
     * history, so the transfer must be corrected first.
     */
    public function closeAt(Employee $employee, CarbonImmutable $date): void
    {
        $day = $date->format(LocalDate::FORMAT);

        if ($employee->assignments()->where('effective_from', '>', $day)->exists()) {
            throw new BusinessRuleException('The employee has a department/location change after this date. Pick a later termination date.', 'history_after_date');
        }

        $employee->assignments()
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $day))
            ->get()
            ->each(fn (EmployeeAssignment $a) => $a->update(['effective_to' => $date]));
    }

    /**
     * Moves the first period's start when the hire date is corrected.
     */
    public function moveStart(Employee $employee, CarbonImmutable $newStart): void
    {
        $first = $employee->assignments()->orderBy('effective_from')->first();

        if ($first === null) {
            return;
        }

        if ($first->effective_to !== null && $newStart->greaterThan($first->effective_to)) {
            throw new BusinessRuleException('The hire date cannot be after the end of the first assignment period.', 'hire_date_after_history');
        }

        $first->update(['effective_from' => $newStart]);
    }

    private function assertWithinEmployment(Employee $employee, CarbonImmutable $date): void
    {
        if ($date->lessThan($employee->hire_date)) {
            throw new BusinessRuleException('The date is before the employee\'s hire date.', 'before_hire_date');
        }

        if ($employee->status === EmployeeStatus::Terminated && $employee->termination_date !== null && $date->greaterThan($employee->termination_date)) {
            throw new BusinessRuleException('The date is after the employee\'s termination date.', 'after_termination_date');
        }
    }
}
