<?php

declare(strict_types=1);

namespace App\Modules\Core\Events;

use Stancl\Tenancy\Contracts\Tenant;

/**
 * Fired inside a tenant's context to (re)apply module defaults such as
 * permissions and missing settings. Listeners must be idempotent: this runs
 * at provisioning time and again whenever `php artisan tenants:seed` is run
 * after a deploy that adds permissions or settings.
 */
final readonly class TenantDefaultsSyncRequested
{
    public function __construct(
        public Tenant $tenant,
    ) {}
}
