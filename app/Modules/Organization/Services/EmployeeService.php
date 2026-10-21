<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Contracts\TenantPlanLimits;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Core\Support\Search;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\UserService;
use App\Modules\Organization\Enums\EmployeeStatus;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\Group;
use App\Modules\Organization\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Employee lifecycle. Business rules:
 *  - creating or reinstating respects the plan's max_employees (active +
 *    inactive count; terminated do not);
 *  - every employee has a department from the hire date onward (dated history
 *    in AssignmentService / MembershipService);
 *  - termination closes history on the termination date and, by default,
 *    deactivates the linked login; nobody can terminate their own record;
 *  - a login can be linked to at most one employee.
 */
final class EmployeeService
{
    public const RELATIONS = ['currentAssignment.department', 'currentAssignment.location', 'currentMemberships.group'];

    public function __construct(
        private readonly AssignmentService $assignments,
        private readonly MembershipService $memberships,
        private readonly EmployeeVisibility $visibility,
        private readonly DepartmentTree $tree,
        private readonly TenantPlanLimits $plan,
        private readonly UserService $users,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{0: string, 1: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, Employee>
     */
    public function paginate(array $filters, ?string $search, array $sort, int $perPage, User $viewer): LengthAwarePaginator
    {
        $query = Employee::query()->with(self::RELATIONS);
        $this->visibility->apply($query, $viewer);

        foreach (['status', 'employment_type'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (isset($filters['department_id'])) {
            // A department filter includes its sub-departments.
            $ids = $this->tree->withDescendants([(int) $filters['department_id']]);
            $query->whereHas('currentAssignment', fn (Builder $a) => $a->whereIn('department_id', $ids));
        }

        if (isset($filters['location_id'])) {
            $query->whereHas('currentAssignment', fn (Builder $a) => $a->where('location_id', (int) $filters['location_id']));
        }

        if (isset($filters['group_id'])) {
            $query->whereHas('currentMemberships', fn (Builder $m) => $m->where('group_id', (int) $filters['group_id']));
        }

        // "jane doe" must match first + last name: every word has to match some column.
        foreach (preg_split('/\s+/', trim((string) $search), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $query->where(fn (Builder $q) => Search::apply($q, ['employee_code', 'first_name', 'last_name', 'email'], $word));
        }

        [$column, $direction] = $sort;
        if ($column === 'name') {
            $query->orderBy('last_name', $direction)->orderBy('first_name', $direction);
        } else {
            $query->orderBy($column, $direction);
        }

        return $query->orderBy('id')->paginate($perPage)->withQueryString();
    }

    public function load(Employee $employee): Employee
    {
        return $employee->load(self::RELATIONS);
    }

    /**
     * @param  array{
     *     employee_code: string, first_name: string, last_name: string, email?: string|null,
     *     phone?: string|null, job_title?: string|null, employment_type: string, hire_date: string,
     *     department_id: int, location_id?: int|null, group_ids?: list<int>,
     *     create_login?: bool, login_password?: string|null
     * }  $data
     * @param  User|null  $actor  null when the system creates employees (imports)
     */
    public function create(array $data, ?User $actor): Employee
    {
        $createLogin = (bool) ($data['create_login'] ?? false);

        if ($createLogin) {
            if (empty($data['email'])) {
                throw ValidationException::withMessages(['email' => 'An email is required to create a login.']);
            }
            $this->users->assertEmailAvailable((string) $data['email']);
        }

        return DB::transaction(function () use ($data, $actor, $createLogin): Employee {
            $this->assertPlanCapacity();

            $employee = Employee::query()->create([
                'employee_code' => $data['employee_code'],
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => isset($data['email']) ? mb_strtolower((string) $data['email']) : null,
                'phone' => $data['phone'] ?? null,
                'job_title' => $data['job_title'] ?? null,
                'employment_type' => $data['employment_type'],
                'status' => EmployeeStatus::Active,
                'hire_date' => $data['hire_date'],
            ]);

            $this->assignments->start($employee, (int) $data['department_id'], $this->nullableInt($data['location_id'] ?? null), $employee->hire_date);

            foreach (array_unique($data['group_ids'] ?? []) as $groupId) {
                $this->memberships->add($employee, Group::query()->findOrFail($groupId), $employee->hire_date);
            }

            if ($createLogin) {
                $user = $this->users->create([
                    'name' => $employee->fullName(),
                    'email' => (string) $employee->email,
                    'password' => (string) $data['login_password'],
                    'role' => RoleName::Employee->value,
                ], $actor);
                $employee->update(['user_id' => $user->id]);
            }

            return $this->load($employee);
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated UpdateEmployeeRequest input
     */
    public function update(Employee $employee, array $data): Employee
    {
        if (isset($data['status']) && $employee->status === EmployeeStatus::Terminated) {
            throw new BusinessRuleException('Reinstate a terminated employee instead of changing their status.', 'employee_terminated');
        }

        return DB::transaction(function () use ($employee, $data): Employee {
            if (isset($data['hire_date'])) {
                $newHire = LocalDate::parse((string) $data['hire_date']);

                if (! $newHire->equalTo($employee->hire_date)) {
                    if ($employee->termination_date !== null && $newHire->greaterThan($employee->termination_date)) {
                        throw new BusinessRuleException('The hire date cannot be after the termination date.', 'hire_date_after_history');
                    }
                    $this->assignments->moveStart($employee, $newHire);
                    $this->memberships->moveStart($employee, $newHire);
                }
            }

            if (array_key_exists('email', $data) && $data['email'] !== null) {
                $data['email'] = mb_strtolower((string) $data['email']);
            }

            $employee->update($data);

            return $this->load($employee);
        });
    }

    public function delete(Employee $employee): void
    {
        DB::transaction(function () use ($employee): void {
            // Free the login so it can be linked to another (correct) record.
            if ($employee->user_id !== null) {
                $employee->update(['user_id' => null]);
            }
            $employee->delete();
        });
    }

    public function terminate(Employee $employee, CarbonImmutable $date, bool $deactivateLogin, User $actor): Employee
    {
        if ($employee->status === EmployeeStatus::Terminated) {
            throw new BusinessRuleException('This employee is already terminated.', 'employee_terminated');
        }

        if ($employee->user_id !== null && $employee->user_id === $actor->id) {
            throw new BusinessRuleException('You cannot terminate your own employee record.', 'self_termination', 403);
        }

        if ($date->lessThan($employee->hire_date)) {
            throw new BusinessRuleException('The termination date is before the hire date.', 'before_hire_date');
        }

        return DB::transaction(function () use ($employee, $date, $deactivateLogin, $actor): Employee {
            $this->assignments->closeAt($employee, $date);
            $this->memberships->closeAllAt($employee, $date);

            $employee->update(['status' => EmployeeStatus::Terminated, 'termination_date' => $date]);

            if ($deactivateLogin && $employee->user_id !== null) {
                $this->users->deactivate($employee->user_id, $actor);
            }

            return $this->load($employee);
        });
    }

    /**
     * Rehire: a new assignment period starts after the termination gap. The
     * department/location default to the last ones held.
     */
    public function reinstate(Employee $employee, CarbonImmutable $from, ?int $departmentId, ?int $locationId): Employee
    {
        if ($employee->status !== EmployeeStatus::Terminated || $employee->termination_date === null) {
            throw new BusinessRuleException('Only terminated employees can be reinstated.', 'employee_not_terminated');
        }

        if (! $from->greaterThan($employee->termination_date)) {
            throw new BusinessRuleException('The rehire date must be after the termination date.', 'rehire_before_termination');
        }

        return DB::transaction(function () use ($employee, $from, $departmentId, $locationId): Employee {
            $this->assertPlanCapacity();

            $last = $employee->assignments()->orderByDesc('effective_from')->first();
            $departmentId ??= $last?->department_id;
            $locationId ??= $departmentId === $last?->department_id ? $last?->location_id : null;

            if ($departmentId === null || ! Department::query()->whereKey($departmentId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages(['department_id' => 'Choose an active department for the rehired employee.']);
            }

            if ($locationId !== null && ! Location::query()->whereKey($locationId)->where('is_active', true)->exists()) {
                $locationId = null;
            }

            $employee->update(['status' => EmployeeStatus::Active, 'termination_date' => null]);
            $this->assignments->start($employee, $departmentId, $locationId, $from);

            return $this->load($employee);
        });
    }

    public function transfer(Employee $employee, int $departmentId, ?int $locationId, CarbonImmutable $from): Employee
    {
        DB::transaction(fn () => $this->assignments->transfer($employee, $departmentId, $locationId, $from));

        return $this->load($employee);
    }

    public function joinGroup(Employee $employee, Group $group, CarbonImmutable $from): Employee
    {
        DB::transaction(fn () => $this->memberships->add($employee, $group, $from));

        return $this->load($employee);
    }

    public function leaveGroup(Employee $employee, Group $group, CarbonImmutable $to): Employee
    {
        DB::transaction(fn () => $this->memberships->end($employee, $group, $to));

        return $this->load($employee);
    }

    public function linkUser(Employee $employee, ?int $userId): Employee
    {
        if ($userId !== null) {
            if ($this->users->activeIdsAmong([$userId]) === []) {
                throw ValidationException::withMessages(['user_id' => 'Choose an active user.']);
            }

            $taken = Employee::withTrashed()->where('user_id', $userId)->whereKeyNot($employee->id)->exists();
            if ($taken) {
                throw ValidationException::withMessages(['user_id' => 'This login is already linked to another employee.']);
            }
        }

        $employee->update(['user_id' => $userId]);

        return $this->load($employee);
    }

    /**
     * Must run inside the write transaction (IMMEDIATE on SQLite), so two
     * concurrent creates cannot both take the last seat.
     */
    private function assertPlanCapacity(): void
    {
        $max = $this->plan->maxEmployees();

        if ($max === null) {
            return;
        }

        $used = Employee::query()->whereIn('status', EmployeeStatus::countedTowardPlan())->count();

        if ($used >= $max) {
            throw new BusinessRuleException(
                "Your plan allows {$max} employees. Terminate employees or upgrade the plan to add more.",
                'plan_limit_reached',
                409,
            );
        }
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
