<?php

declare(strict_types=1);

namespace App\Modules\Central\Policies;

use App\Modules\Central\Models\SuperAdmin;
use App\Modules\Central\Models\Tenant;

/**
 * Only super admins manage tenants. Typing the first argument as SuperAdmin
 * makes the Gate deny any other authenticatable (e.g. a tenant user).
 */
final class TenantPolicy
{
    public function viewAny(SuperAdmin $admin): bool
    {
        return true;
    }

    public function view(SuperAdmin $admin, Tenant $tenant): bool
    {
        return true;
    }

    public function create(SuperAdmin $admin): bool
    {
        return true;
    }

    public function update(SuperAdmin $admin, Tenant $tenant): bool
    {
        return true;
    }

    public function suspend(SuperAdmin $admin, Tenant $tenant): bool
    {
        return true;
    }

    public function activate(SuperAdmin $admin, Tenant $tenant): bool
    {
        return true;
    }

    public function delete(SuperAdmin $admin, Tenant $tenant): bool
    {
        return true;
    }
}
