<?php

declare(strict_types=1);

namespace App\Modules\Identity\Policies;

use App\Modules\Identity\Models\User;

final class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can('users.view');
    }

    public function view(User $actor, User $user): bool
    {
        return $actor->can('users.view');
    }

    public function create(User $actor): bool
    {
        return $actor->can('users.create');
    }

    /**
     * A Tenant Admin account can only be edited by another Tenant Admin, so an
     * HR Manager cannot reset an admin's password and take over the workspace.
     */
    public function update(User $actor, User $user): bool
    {
        return $actor->can('users.update') && (! $user->isTenantAdmin() || $actor->isTenantAdmin());
    }

    public function delete(User $actor, User $user): bool
    {
        return $actor->can('users.delete')
            && ! $actor->is($user)
            && (! $user->isTenantAdmin() || $actor->isTenantAdmin());
    }
}
