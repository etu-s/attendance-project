<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Limits of the current tenant's plan, readable from tenant context without
 * touching central tables. Implemented by the Central module.
 */
interface TenantPlanLimits
{
    /**
     * Maximum non-terminated employees, or null for unlimited.
     */
    public function maxEmployees(): ?int;
}
