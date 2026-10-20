<?php

declare(strict_types=1);

namespace App\Modules\Core\Tenancy;

/**
 * Input every module may need when a new tenant database is prepared
 * (e.g. Identity creates the first Tenant Admin, Core seeds the timezone).
 */
final readonly class TenantProvisioningData
{
    public function __construct(
        public string $name,
        public string $subdomain,
        public int $planId,
        public string $timezone,
        public string $adminName,
        public string $adminEmail,
        #[\SensitiveParameter]
        public string $adminPassword,
    ) {}
}
