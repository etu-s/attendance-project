<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The single rule for "which employees may this user see". Every module that
 * lists employee data (attendance, leave, reports) must scope through this.
 *
 *  - employees.view-all (Tenant Admin, HR): everyone.
 *  - employees.view (Department Manager): employees currently assigned to a
 *    department they manage or any department below it, or currently in a
 *    group they manage; plus themselves.
 *  - anyone else: only their own employee record.
 */
final class EmployeeVisibility
{
    public function __construct(
        private readonly DepartmentTree $tree,
    ) {}

    public function seesEveryone(User $user): bool
    {
        return $user->can('employees.view-all');
    }

    /**
     * @param  Builder<Employee>  $query
     */
    public function apply(Builder $query, User $user): void
    {
        if ($this->seesEveryone($user)) {
            return;
        }

        if (! $user->can('employees.view')) {
            $query->where('employees.user_id', $user->id);

            return;
        }

        [$departmentIds, $groupIds] = $this->managedScope($user);

        $query->where(function (Builder $visible) use ($user, $departmentIds, $groupIds): void {
            $visible->where('employees.user_id', $user->id)
                ->orWhereHas('currentAssignment', fn (Builder $a) => $a->whereIn('department_id', $departmentIds))
                ->orWhereHas('currentMemberships', fn (Builder $m) => $m->whereIn('group_id', $groupIds));
        });
    }

    public function canSee(User $user, Employee $employee): bool
    {
        if ($this->seesEveryone($user) || $employee->user_id === $user->id) {
            return true;
        }

        $query = Employee::query()->whereKey($employee->id);
        $this->apply($query, $user);

        return $query->exists();
    }

    /**
     * @return array{0: list<int>, 1: list<int>} [department ids incl. descendants, group ids]
     */
    public function managedScope(User $user): array
    {
        $departments = DB::table('department_managers')->where('user_id', $user->id)->pluck('department_id')
            ->map(fn ($id) => (int) $id)->all();
        $groups = DB::table('group_managers')->where('user_id', $user->id)->pluck('group_id')
            ->map(fn ($id) => (int) $id)->all();

        return [$this->tree->withDescendants($departments), $groups];
    }
}
