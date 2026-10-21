<?php

declare(strict_types=1);

namespace App\Modules\Organization\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Services\EmployeeVisibility;

/**
 * Reads are scoped by EmployeeVisibility (a department manager only sees
 * their people); writes need the specific permission and are HR/admin only.
 */
final class EmployeePolicy
{
    public function __construct(
        private readonly EmployeeVisibility $visibility,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->can('employees.view-all') || $actor->can('employees.view');
    }

    public function view(User $actor, Employee $employee): bool
    {
        return $this->visibility->canSee($actor, $employee);
    }

    public function create(User $actor): bool
    {
        return $actor->can('employees.create');
    }

    public function update(User $actor, Employee $employee): bool
    {
        return $actor->can('employees.update') && $this->visibility->canSee($actor, $employee);
    }

    public function terminate(User $actor, Employee $employee): bool
    {
        return $actor->can('employees.terminate') && $this->visibility->canSee($actor, $employee);
    }

    public function delete(User $actor, Employee $employee): bool
    {
        return $actor->can('employees.delete');
    }

    public function import(User $actor): bool
    {
        return $actor->can('employees.import');
    }
}
