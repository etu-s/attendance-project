<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Core\Events\TenantDefaultsSyncRequested;
use App\Modules\Identity\Services\PermissionSynchronizer;

final class SyncRolesAndPermissions
{
    public function __construct(
        private readonly PermissionSynchronizer $synchronizer,
    ) {}

    public function handle(TenantDefaultsSyncRequested $event): void
    {
        $this->synchronizer->sync();
    }
}
