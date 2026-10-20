<?php

declare(strict_types=1);

namespace App\Modules\Central\Policies;

use App\Modules\Central\Models\Plan;
use App\Modules\Central\Models\SuperAdmin;

/**
 * Only super admins manage plans. Typing the first argument as SuperAdmin
 * makes the Gate deny any other authenticatable (e.g. a tenant user).
 */
final class PlanPolicy
{
    public function viewAny(SuperAdmin $admin): bool
    {
        return true;
    }

    public function view(SuperAdmin $admin, Plan $plan): bool
    {
        return true;
    }

    public function create(SuperAdmin $admin): bool
    {
        return true;
    }

    public function update(SuperAdmin $admin, Plan $plan): bool
    {
        return true;
    }

    public function delete(SuperAdmin $admin, Plan $plan): bool
    {
        return true;
    }
}
