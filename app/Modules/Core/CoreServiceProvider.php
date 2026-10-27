<?php

declare(strict_types=1);

namespace App\Modules\Core;

use App\Modules\Core\Console\BackupDatabasesCommand;
use App\Modules\Core\Contracts\LeaveCalendar;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Enums\SettingType;
use App\Modules\Core\Events\TenantDatabaseProvisioned;
use App\Modules\Core\Events\TenantDefaultsSyncRequested;
use App\Modules\Core\Listeners\ApplyProvisioningSettings;
use App\Modules\Core\Listeners\SeedDefaultSettings;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Permissions\PermissionCatalog;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Services\DatabaseBackupService;
use App\Modules\Core\Services\SettingsService;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Core\Settings\SettingsRegistry;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Core\Support\NoLeaveCalendar;
use App\Modules\Core\Support\TenantClock;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

final class CoreServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsRegistry::class);
        $this->app->singleton(PermissionCatalog::class);
        $this->app->singleton(SettingsService::class);
        $this->app->singleton(AuditLogger::class);
        $this->app->singleton(DatabaseBackupService::class);
        $this->app->singleton(TenantClock::class);
        // The Leave module rebinds this (registered after Core).
        $this->app->singleton(LeaveCalendar::class, NoLeaveCalendar::class);
    }

    public function boot(): void
    {
        $this->bootModule();

        // Short aliases instead of class names in polymorphic columns (audit_logs,
        // model_has_roles, ...) so stored data survives class renames.
        Relation::enforceMorphMap(['setting' => Setting::class]);

        // Surfaces N+1 queries and silently dropped attributes during development and tests.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        $this->registerSettings();
        $this->registerPermissions();
        $this->registerRateLimiters();

        Event::listen(TenantDefaultsSyncRequested::class, SeedDefaultSettings::class);
        Event::listen(TenantDatabaseProvisioned::class, ApplyProvisioningSettings::class);

        if ($this->app->runningInConsole()) {
            $this->commands([BackupDatabasesCommand::class]);
        }
    }

    private function registerSettings(): void
    {
        $this->app->make(SettingsRegistry::class)->register(
            new SettingDefinition(
                key: 'general.timezone',
                type: SettingType::String,
                default: 'UTC',
                label: 'Timezone',
                description: 'Used to display times and to decide which calendar day a timestamp belongs to.',
                rules: ['timezone:all'],
            ),
            new SettingDefinition(
                key: 'general.date_format',
                type: SettingType::String,
                default: 'Y-m-d',
                label: 'Date format',
                options: ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd.m.Y', 'd M Y'],
            ),
            new SettingDefinition(
                key: 'general.time_format',
                type: SettingType::String,
                default: 'H:i',
                label: 'Time format',
                options: ['H:i', 'h:i A'],
            ),
            new SettingDefinition(
                key: 'general.week_start',
                type: SettingType::Integer,
                default: 1,
                label: 'First day of the week',
                description: '0 = Sunday, 1 = Monday, ... 6 = Saturday.',
                options: [0, 1, 2, 3, 4, 5, 6],
            ),
        );
    }

    private function registerPermissions(): void
    {
        $catalog = $this->app->make(PermissionCatalog::class);

        $catalog->register('settings.view', [RoleName::HrManager]);
        $catalog->register('settings.update');
        $catalog->register('audit-logs.view', [RoleName::HrManager]);
    }

    private function registerRateLimiters(): void
    {
        // Keyed by host as well, so the same email on two tenants is limited independently.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute((int) config('ams.rate_limits.login_per_minute'))
            ->by($request->getHost().'|'.mb_strtolower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute((int) config('ams.rate_limits.api_per_minute'))
            ->by($request->getHost().'|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
