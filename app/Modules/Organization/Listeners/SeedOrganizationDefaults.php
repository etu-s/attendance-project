<?php

declare(strict_types=1);

namespace App\Modules\Organization\Listeners;

use App\Modules\Core\Events\TenantDefaultsSyncRequested;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Services\OrganizationProfileService;

/**
 * Every tenant gets an organization profile and, if it has no departments at
 * all, a "General" department, because every employee must belong to one.
 * Idempotent: runs at provisioning and on every `tenants:seed`.
 */
final class SeedOrganizationDefaults
{
    public function __construct(
        private readonly OrganizationProfileService $profile,
    ) {}

    public function handle(TenantDefaultsSyncRequested $event): void
    {
        $this->profile->get();

        if (! Department::query()->exists()) {
            Department::query()->create(['name' => 'General', 'code' => 'GEN']);
        }
    }
}
