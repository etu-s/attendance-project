<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Organization\Enums\EmployeeStatus;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\Group;
use App\Modules\Organization\Models\GroupMembership;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Effective-dated group memberships. on() returns an employee's groups on a
 * date in precedence order (lowest priority number first), which is the order
 * scheduling uses to pick the group schedule.
 */
final class MembershipService
{
    /**
     * @return Collection<int, GroupMembership>
     */
    public function on(Employee $employee, CarbonImmutable $date): Collection
    {
        $day = $date->format(LocalDate::FORMAT);

        return $employee->memberships()
            ->with('group')
            ->where('effective_from', '<=', $day)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $day))
            ->get()
            ->sortBy(fn (GroupMembership $m) => $m->group->priority)
            ->values();
    }

    /**
     * @return Collection<int, GroupMembership>
     */
    public function history(Employee $employee): Collection
    {
        return $employee->memberships()->with('group')->orderByDesc('effective_from')->get();
    }

    /**
     * Memberships (with group) of many employees overlapping a date range, in one query.
     *
     * @param  list<int>  $employeeIds
     * @return \Illuminate\Support\Collection<int, Collection<int, GroupMembership>> employee id => memberships
     */
    public function forEmployees(array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): \Illuminate\Support\Collection
    {
        return GroupMembership::query()
            ->with('group')
            ->whereIn('employee_id', $employeeIds)
            ->where('effective_from', '<=', $to->format(LocalDate::FORMAT))
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $from->format(LocalDate::FORMAT)))
            ->get()
            ->groupBy('employee_id');
    }

    public function add(Employee $employee, Group $group, CarbonImmutable $from): GroupMembership
    {
        if (! $group->is_active) {
            throw new BusinessRuleException('This group is inactive.', 'group_inactive');
        }

        if ($from->lessThan($employee->hire_date)) {
            throw new BusinessRuleException('The date is before the employee\'s hire date.', 'before_hire_date');
        }

        if ($employee->status === EmployeeStatus::Terminated) {
            throw new BusinessRuleException('Terminated employees cannot join groups.', 'employee_terminated');
        }

        $day = $from->format(LocalDate::FORMAT);
        $overlaps = $employee->memberships()
            ->where('group_id', $group->id)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', $day))
            ->exists();

        if ($overlaps) {
            throw new BusinessRuleException('The employee is already in this group on or after this date.', 'membership_overlap');
        }

        return GroupMembership::query()->create([
            'employee_id' => $employee->id,
            'group_id' => $group->id,
            'effective_from' => $from,
            'effective_to' => null,
        ]);
    }

    /**
     * Ends the employee's open membership of $group on $to (inclusive). A
     * membership that would only have started after $to never took effect,
     * so it is removed rather than given an end before its start.
     */
    public function end(Employee $employee, Group $group, CarbonImmutable $to): void
    {
        $membership = $employee->memberships()
            ->where('group_id', $group->id)
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $to->format(LocalDate::FORMAT)))
            ->orderBy('effective_from')
            ->first()
            ?? throw new BusinessRuleException('The employee is not in this group after this date.', 'not_a_member');

        if ($membership->effective_from->greaterThan($to)) {
            $membership->delete();

            return;
        }

        $membership->update(['effective_to' => $to]);
    }

    /**
     * Ends every membership on $date (termination). Refuses when a membership
     * starts after $date, mirroring AssignmentService::closeAt().
     */
    public function closeAllAt(Employee $employee, CarbonImmutable $date): void
    {
        $day = $date->format(LocalDate::FORMAT);

        if ($employee->memberships()->where('effective_from', '>', $day)->exists()) {
            throw new BusinessRuleException('The employee joins a group after this date. Pick a later termination date.', 'history_after_date');
        }

        $employee->memberships()
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $day))
            ->get()
            ->each(fn (GroupMembership $m) => $m->update(['effective_to' => $date]));
    }

    /**
     * Pulls memberships that started before a corrected (later) hire date up to it.
     */
    public function moveStart(Employee $employee, CarbonImmutable $newStart): void
    {
        $employee->memberships()
            ->where('effective_from', '<', $newStart->format(LocalDate::FORMAT))
            ->get()
            ->each(function (GroupMembership $membership) use ($newStart): void {
                if ($membership->effective_to !== null && $membership->effective_to->lessThan($newStart)) {
                    throw new BusinessRuleException('A group membership ends before the new hire date.', 'hire_date_after_history');
                }
                $membership->update(['effective_from' => $newStart]);
            });
    }
}
