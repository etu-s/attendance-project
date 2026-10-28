<?php

declare(strict_types=1);

namespace App\Modules\Overtime;

use App\Modules\Attendance\Events\AttendanceRecalculated;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Enums\SettingType;
use App\Modules\Core\Permissions\PermissionCatalog;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Core\Settings\SettingsRegistry;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Overtime\Listeners\RecalculateOnAttendanceChange;
use App\Modules\Overtime\Models\OvertimeRecord;
use App\Modules\Overtime\Services\OvertimeAccess;
use App\Modules\Overtime\Services\OvertimeCalculator;
use App\Modules\Overtime\Services\OvertimeQuery;
use App\Modules\Overtime\Services\OvertimeService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;

/**
 * Overtime: derived from calculated attendance (AttendanceRecalculated),
 * approved after the fact, summarised per employee. Rules and rates are
 * workspace settings.
 */
final class OvertimeServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OvertimeCalculator::class);
        $this->app->singleton(OvertimeQuery::class);

        // OvertimeAccess memoizes visibility per user: scoped so it resets per request / job.
        $this->app->scoped(OvertimeAccess::class);
        $this->app->scoped(OvertimeService::class);
    }

    public function boot(): void
    {
        $this->bootModule();

        Relation::enforceMorphMap(['overtime_record' => OvertimeRecord::class]);

        Event::listen(AttendanceRecalculated::class, RecalculateOnAttendanceChange::class);

        $catalog = $this->app->make(PermissionCatalog::class);
        $catalog->register('overtime.view', [RoleName::HrManager, RoleName::DepartmentManager]);
        $catalog->register('overtime.approve', [RoleName::HrManager, RoleName::DepartmentManager]);
        $catalog->register('overtime.manage', [RoleName::HrManager]);

        $this->registerSettings();
    }

    private function registerSettings(): void
    {
        $rate = ['integer', 'min:100', 'max:500'];

        $this->app->make(SettingsRegistry::class)->register(
            new SettingDefinition(
                key: 'overtime.min_minutes',
                type: SettingType::Integer,
                default: 30,
                label: 'Minimum overtime per day (minutes)',
                description: 'Less than this in a day is not overtime.',
                rules: ['integer', 'min:0', 'max:600'],
            ),
            new SettingDefinition(
                key: 'overtime.rounding_minutes',
                type: SettingType::Integer,
                default: 15,
                label: 'Round overtime down to (minutes)',
                description: '0 keeps exact minutes.',
                rules: ['integer', 'min:0', 'max:60'],
            ),
            new SettingDefinition(
                key: 'overtime.daily_cap_minutes',
                type: SettingType::Integer,
                default: 0,
                label: 'Most overtime per day (minutes)',
                description: '0 means no cap.',
                rules: ['integer', 'min:0', 'max:1440'],
            ),
            new SettingDefinition(
                key: 'overtime.count_early_arrival',
                type: SettingType::Boolean,
                default: false,
                label: 'Count time before the shift',
                description: 'Off: only time after the scheduled end counts on a working day.',
            ),
            new SettingDefinition(
                key: 'overtime.rate_workday',
                type: SettingType::Integer,
                default: 150,
                label: 'Working-day rate (%)',
                description: '150 = time and a half.',
                rules: $rate,
            ),
            new SettingDefinition(
                key: 'overtime.rate_rest_day',
                type: SettingType::Integer,
                default: 200,
                label: 'Rest-day rate (%)',
                description: 'Work on rest days, days off and unscheduled days.',
                rules: $rate,
            ),
            new SettingDefinition(
                key: 'overtime.rate_holiday',
                type: SettingType::Integer,
                default: 200,
                label: 'Holiday rate (%)',
                rules: $rate,
            ),
        );
    }
}
