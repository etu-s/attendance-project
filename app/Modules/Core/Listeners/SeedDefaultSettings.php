<?php

declare(strict_types=1);

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Events\TenantDefaultsSyncRequested;
use App\Modules\Core\Services\SettingsService;

final class SeedDefaultSettings
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function handle(TenantDefaultsSyncRequested $event): void
    {
        $this->settings->seedDefaults();
    }
}
