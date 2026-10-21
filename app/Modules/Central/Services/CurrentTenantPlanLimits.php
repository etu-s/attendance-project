<?php

declare(strict_types=1);

namespace App\Modules\Central\Services;

use App\Modules\Central\Models\Tenant;
use App\Modules\Core\Contracts\TenantPlanLimits;
use LogicException;

final class CurrentTenantPlanLimits implements TenantPlanLimits
{
    public function maxEmployees(): ?int
    {
        $tenant = tenant();

        if (! $tenant instanceof Tenant) {
            throw new LogicException('Plan limits require tenant context.');
        }

        // Fresh read: a super admin may have changed the plan since tenancy started.
        $max = $tenant->plan()->value('max_employees');

        return $max === null ? null : (int) $max;
    }
}
