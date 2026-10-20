<?php

declare(strict_types=1);

namespace App\Modules\Core\Events;

use App\Modules\Core\Tenancy\TenantProvisioningData;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Fired synchronously, inside the new tenant's context, after its database is
 * created and migrated. Modules listen to seed their own defaults. A listener
 * that throws aborts provisioning and the tenant is removed.
 */
final readonly class TenantDatabaseProvisioned
{
    public function __construct(
        public Tenant $tenant,
        public TenantProvisioningData $data,
    ) {}
}
