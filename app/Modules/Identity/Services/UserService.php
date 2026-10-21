<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Core\Enums\AuditAction;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\Search;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Events\UserDeleted;
use App\Modules\Identity\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Business rules for tenant user accounts:
 *  - every user has exactly one role;
 *  - only a Tenant Admin may grant, revoke or modify the Tenant Admin role
 *    (enforced in UserPolicy and the Form Requests, re-checked here);
 *  - a tenant always keeps at least one active Tenant Admin;
 *  - nobody can change their own role or status, or delete themselves.
 */
final class UserService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{0: string, 1: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, User>
     */
    public function paginate(array $filters, ?string $search, array $sort, int $perPage): LengthAwarePaginator
    {
        $query = User::query()->with('roles');

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['role'])) {
            $query->whereHas('roles', fn (Builder $roles) => $roles->where('name', $filters['role']));
        }

        Search::apply($query, ['name', 'email'], $search);

        [$column, $direction] = $sort;

        return $query->orderBy($column, $direction)->orderBy('id')->paginate($perPage)->withQueryString();
    }

    /**
     * @param  array{name: string, email: string, password: string, role: string, status?: string}  $data
     */
    public function create(array $data, ?User $actor): User
    {
        $role = RoleName::from($data['role']);
        $this->assertMayAssign($actor, $role);

        return DB::transaction(function () use ($data, $role): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => mb_strtolower($data['email']),
                'password' => $data['password'],
                'status' => $data['status'] ?? UserStatus::Active->value,
            ]);

            $this->setRole($user, $role, null);

            return $user->load('roles');
        });
    }

    /**
     * @param  array{name?: string, email?: string, password?: string|null, role?: string, status?: string}  $data
     */
    public function update(User $user, array $data, User $actor): User
    {
        $newRole = isset($data['role']) ? RoleName::from($data['role']) : null;
        $newStatus = isset($data['status']) ? UserStatus::from($data['status']) : null;
        $currentRole = $user->roleName();

        $roleChanges = $newRole !== null && $newRole !== $currentRole;
        $statusChanges = $newStatus !== null && $newStatus !== $user->status;

        if ($user->is($actor) && ($roleChanges || $statusChanges)) {
            throw new BusinessRuleException('You cannot change your own role or status.', 'self_modification', 403);
        }

        if ($roleChanges) {
            $this->assertMayAssign($actor, $newRole);
        }

        return DB::transaction(function () use ($user, $data, $newRole, $currentRole, $roleChanges, $statusChanges, $newStatus): User {
            $losesAdmin = $currentRole === RoleName::TenantAdmin
                && (($roleChanges && $newRole !== RoleName::TenantAdmin) || ($statusChanges && $newStatus === UserStatus::Inactive));

            if ($losesAdmin) {
                $this->assertAnotherActiveAdminExists($user);
            }

            $attributes = array_filter([
                'name' => $data['name'] ?? null,
                'email' => isset($data['email']) ? mb_strtolower($data['email']) : null,
                'status' => $data['status'] ?? null,
                'password' => $data['password'] ?? null,
            ], fn ($value) => $value !== null && $value !== '');

            $user->fill($attributes)->save();

            if ($roleChanges) {
                $this->setRole($user, $newRole, $currentRole);
            }

            return $user->load('roles');
        });
    }

    public function delete(User $user, User $actor): void
    {
        if ($user->is($actor)) {
            throw new BusinessRuleException('You cannot delete your own account.', 'self_deletion', 403);
        }

        DB::transaction(function () use ($user): void {
            if ($user->isTenantAdmin() && $user->isActive()) {
                $this->assertAnotherActiveAdminExists($user);
            }

            // Device/API tokens die with the account; the role is kept for history.
            $user->tokens()->delete();
            $user->delete();

            event(new UserDeleted((int) $user->getKey()));
        });
    }

    /**
     * Blocks sign-in for a user (e.g. their employee record was terminated).
     * Goes through update(), so the last-active-admin rule still applies.
     */
    public function deactivate(int $userId, User $actor): void
    {
        $user = User::query()->with('roles')->find($userId);

        if ($user === null || ! $user->isActive()) {
            return;
        }

        $this->update($user, ['status' => UserStatus::Inactive->value], $actor);
    }

    /**
     * Display data for users referenced by other modules (managers, linked logins).
     *
     * @param  list<int>  $ids
     * @return array<int, array{id: int, name: string, email: string, status: string}>
     */
    public function summaries(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereKey(array_values(array_unique($ids)))
            ->get(['id', 'name', 'email', 'status'])
            ->mapWithKeys(fn (User $user) => [$user->id => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status->value,
            ]])
            ->all();
    }

    /**
     * Which of the given ids are existing, active (not deleted) users.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function activeIdsAmong(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return User::query()
            ->whereKey(array_values(array_unique($ids)))
            ->where('status', UserStatus::Active)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * For other modules that create logins (e.g. an employee's login): the
     * email must not belong to any account, including deleted ones.
     *
     * @param  string  $field  the input field the error is reported on
     */
    public function assertEmailAvailable(string $email, string $field = 'email'): void
    {
        if (User::withTrashed()->where('email', mb_strtolower($email))->exists()) {
            throw ValidationException::withMessages([$field => 'A login with this email already exists.']);
        }
    }

    /**
     * First admin of a brand-new tenant (called during provisioning).
     */
    public function createTenantAdmin(string $name, string $email, #[\SensitiveParameter] string $password): User
    {
        return $this->create([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'role' => RoleName::TenantAdmin->value,
        ], null);
    }

    /**
     * A null actor is the system itself (provisioning, seeders).
     */
    private function assertMayAssign(?User $actor, RoleName $role): void
    {
        if ($role === RoleName::TenantAdmin && $actor !== null && ! $actor->isTenantAdmin()) {
            throw new BusinessRuleException('Only a Tenant Admin can grant the Tenant Admin role.', 'admin_role_restricted', 403);
        }
    }

    private function assertAnotherActiveAdminExists(User $user): void
    {
        $others = User::query()
            ->whereKeyNot($user->getKey())
            ->where('status', UserStatus::Active)
            ->whereHas('roles', fn (Builder $roles) => $roles->where('name', RoleName::TenantAdmin->value))
            ->exists();

        if (! $others) {
            throw new BusinessRuleException('A workspace must keep at least one active Tenant Admin.', 'last_tenant_admin', 409);
        }
    }

    private function setRole(User $user, RoleName $role, ?RoleName $previous): void
    {
        $user->syncRoles([$role->value]);

        // Role changes live in a pivot table, which model events do not see.
        $this->audit->record(
            $user,
            AuditAction::RoleChanged,
            $previous === null ? null : ['role' => $previous->value],
            ['role' => $role->value],
        );
    }
}
