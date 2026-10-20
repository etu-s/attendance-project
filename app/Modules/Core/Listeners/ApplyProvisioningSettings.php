<?php

declare(strict_types=1);

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Events\TenantDatabaseProvisioned;
use App\Modules\Core\Services\SettingsService;

final class ApplyProvisioningSettings
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function handle(TenantDatabaseProvisioned $event): void
    {
        $this->settings->update(['general.timezone' => $event->data->timezone]);
    }
}
