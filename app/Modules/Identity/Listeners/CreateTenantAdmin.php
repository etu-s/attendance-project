<?php

declare(strict_types=1);

namespace App\Modules\Identity\Listeners;

use App\Modules\Core\Events\TenantDatabaseProvisioned;
use App\Modules\Identity\Services\UserService;

final class CreateTenantAdmin
{
    public function __construct(
        private readonly UserService $users,
    ) {}

    public function handle(TenantDatabaseProvisioned $event): void
    {
        $this->users->createTenantAdmin(
            $event->data->adminName,
            $event->data->adminEmail,
            $event->data->adminPassword,
        );
    }
}
