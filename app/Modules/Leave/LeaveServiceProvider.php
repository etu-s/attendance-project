<?php

declare(strict_types=1);

namespace App\Modules\Leave;

use App\Modules\Core\Contracts\LeaveCalendar;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Enums\SettingType;
use App\Modules\Core\Permissions\PermissionCatalog;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Core\Settings\SettingsRegistry;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Leave\Console\AccrueLeaveCommand;
use App\Modules\Leave\Listeners\RecountOnScheduleChange;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveAccrualService;
use App\Modules\Leave\Services\LeaveBalanceService;
use App\Modules\Leave\Services\LeaveCalendarService;
use App\Modules\Leave\Services\LeaveDayCounter;
use App\Modules\Leave\Services\LeaveLedger;
use App\Modules\Leave\Services\LeaveQuery;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Modules\Leave\Services\LeaveRules;
use App\Modules\Leave\Services\LeaveTypeService;
use App\Modules\Leave\Support\LeaveYears;
use App\Modules\Scheduling\Events\ScheduleChanged;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;

/**
 * Leave: types, requests with approval, and balances kept as an append-only
 * ledger. Feeds attendance through Core's LeaveCalendar contract and the
 * LeaveChanged event; recounts requests when schedules or holidays change.
 */
final class LeaveServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        foreach ([
            LeaveYears::class, LeaveDayCounter::class, LeaveLedger::class, LeaveBalanceService::class,
            LeaveAccrualService::class, LeaveQuery::class, LeaveTypeService::class,
        ] as $service) {
            $this->app->singleton($service);
        }

        // LeaveRules memoizes visibility per user: scoped so it resets per request / job.
        $this->app->scoped(LeaveRules::class);
        $this->app->scoped(LeaveRequestService::class);

        $this->app->singleton(LeaveCalendar::class, LeaveCalendarService::class);
    }

    public function boot(): void
    {
        $this->bootModule();

        Relation::enforceMorphMap([
            'leave_type' => LeaveType::class,
            'leave_request' => LeaveRequest::class,
        ]);

        Event::listen(ScheduleChanged::class, RecountOnScheduleChange::class);

        if ($this->app->runningInConsole()) {
            $this->commands([AccrueLeaveCommand::class]);
        }

        $catalog = $this->app->make(PermissionCatalog::class);
        $catalog->register('leave.request', [RoleName::HrManager, RoleName::DepartmentManager, RoleName::Employee]);
        $catalog->register('leave.view', [RoleName::HrManager, RoleName::DepartmentManager]);
        $catalog->register('leave.approve', [RoleName::HrManager, RoleName::DepartmentManager]);
        $catalog->register('leave.manage', [RoleName::HrManager]);

        $this->app->make(SettingsRegistry::class)->register(new SettingDefinition(
            key: 'leave.year_start_month',
            type: SettingType::Integer,
            default: 1,
            label: 'Leave year starts in',
            description: 'Yearly grants, carry-forward and expiry follow this year (1 = January).',
            options: range(1, 12),
        ));
    }
}
