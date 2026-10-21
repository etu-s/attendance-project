<?php

declare(strict_types=1);

namespace App\Modules\Timetable;

use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Enums\SettingType;
use App\Modules\Core\Permissions\PermissionCatalog;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Core\Settings\SettingsRegistry;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Timetable\Models\Shift;
use App\Modules\Timetable\Models\Timetable;
use App\Modules\Timetable\Services\ShiftCalendar;
use App\Modules\Timetable\Services\ShiftService;
use App\Modules\Timetable\Services\TimetableRules;
use App\Modules\Timetable\Services\TimetableService;
use App\Modules\Timetable\Services\UsageRegistry;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Timetable & Shifts: what a working day looks like. Timetables are work
 * periods (fixed or flexible, with breaks, grace and punch windows); shifts
 * repeat timetables over a day or week cycle. Later modules use:
 *  - ShiftCalendar::expand() to lay a shift onto real dates (UTC, DST-aware);
 *  - TimetableRules::for() for grace/windows after workspace defaults;
 *  - UsageRegistry::whenShiftInUse() to lock time rules once scheduled.
 */
final class TimetableServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        foreach ([UsageRegistry::class, TimetableRules::class, TimetableService::class, ShiftService::class, ShiftCalendar::class] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        $this->bootModule();

        Relation::enforceMorphMap([
            'timetable' => Timetable::class,
            'shift' => Shift::class,
        ]);

        $this->registerPermissions();
        $this->registerSettings();
    }

    private function registerPermissions(): void
    {
        $catalog = $this->app->make(PermissionCatalog::class);

        $catalog->register('timetables.view', [RoleName::HrManager, RoleName::DepartmentManager]);
        $catalog->register('timetables.manage', [RoleName::HrManager]);
        $catalog->register('shifts.view', [RoleName::HrManager, RoleName::DepartmentManager]);
        $catalog->register('shifts.manage', [RoleName::HrManager]);
    }

    /**
     * Workspace defaults; a timetable overrides each one with its own value.
     */
    private function registerSettings(): void
    {
        $minutes = fn (int $max) => ['integer', 'min:0', "max:{$max}"];

        $this->app->make(SettingsRegistry::class)->register(
            new SettingDefinition(
                key: 'attendance.late_grace_minutes',
                type: SettingType::Integer,
                default: 5,
                label: 'Late grace (minutes)',
                description: 'Arriving within this many minutes after the start is not late.',
                rules: $minutes(240),
            ),
            new SettingDefinition(
                key: 'attendance.early_leave_grace_minutes',
                type: SettingType::Integer,
                default: 5,
                label: 'Early-leave grace (minutes)',
                description: 'Leaving within this many minutes before the end is not early.',
                rules: $minutes(240),
            ),
            new SettingDefinition(
                key: 'attendance.check_in_before_minutes',
                type: SettingType::Integer,
                default: 120,
                label: 'Accept check-in from (minutes before start)',
                description: 'Earlier punches are not counted as the check-in for that timetable.',
                rules: $minutes(720),
            ),
            new SettingDefinition(
                key: 'attendance.check_out_after_minutes',
                type: SettingType::Integer,
                default: 240,
                label: 'Accept check-out until (minutes after end)',
                description: 'Later punches are not counted as the check-out for that timetable.',
                rules: $minutes(720),
            ),
        );
    }
}
