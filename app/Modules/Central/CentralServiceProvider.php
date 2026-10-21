<?php

declare(strict_types=1);

namespace App\Modules\Central;

use App\Modules\Central\Models\Domain;
use App\Modules\Central\Models\Plan;
use App\Modules\Central\Models\SuperAdmin;
use App\Modules\Central\Models\Tenant;
use App\Modules\Central\Policies\PlanPolicy;
use App\Modules\Central\Policies\TenantPolicy;
use App\Modules\Central\Services\CurrentTenantPlanLimits;
use App\Modules\Central\Services\PlanService;
use App\Modules\Central\Services\TenantProvisioner;
use App\Modules\Central\Services\TenantService;
use App\Modules\Core\Contracts\TenantPlanLimits;
use App\Modules\Core\Support\ModuleServiceProvider;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;

final class CentralServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantProvisioner::class);
        $this->app->singleton(TenantService::class);
        $this->app->singleton(PlanService::class);
        $this->app->singleton(TenantPlanLimits::class, CurrentTenantPlanLimits::class);
    }

    public function boot(): void
    {
        $this->bootModule();

        Relation::enforceMorphMap([
            'tenant' => Tenant::class,
            'domain' => Domain::class,
            'plan' => Plan::class,
            'super_admin' => SuperAdmin::class,
        ]);

        Gate::policy(Plan::class, PlanPolicy::class);
        Gate::policy(Tenant::class, TenantPolicy::class);
    }
}
