<?php

declare(strict_types=1);

namespace App\Modules\Payroll;

use App\Modules\Core\Contracts\PeriodLock;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Permissions\PermissionCatalog;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Payroll\Services\PayrollPeriodLock;
use App\Modules\Payroll\Services\PayrollPeriodService;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Payroll periods: lock finished days so attendance, leave, overtime and
 * schedules can no longer change them. Implements Core's PeriodLock, which
 * the other modules consult without depending on this one.
 */
final class PayrollServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PeriodLock::class, PayrollPeriodLock::class);
        $this->app->scoped(PayrollPeriodService::class);
    }

    public function boot(): void
    {
        $this->bootModule();

        Relation::enforceMorphMap(['payroll_period' => PayrollPeriod::class]);

        $catalog = $this->app->make(PermissionCatalog::class);
        $catalog->register('payroll-periods.view', [RoleName::HrManager]);
        $catalog->register('payroll-periods.manage', [RoleName::HrManager]);
        // Tenant Admin only (it holds every permission).
        $catalog->register('payroll-periods.unlock');
    }
}
