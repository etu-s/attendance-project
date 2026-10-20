<?php

declare(strict_types=1);

namespace App\Modules\Central\Enums;

enum TenantStatus: string
{
    /** Database is being created and seeded; tenant routes answer 503. */
    case Provisioning = 'provisioning';
    case Active = 'active';
    /** Tenant routes answer 403; data is kept. Required before deletion. */
    case Suspended = 'suspended';
}
