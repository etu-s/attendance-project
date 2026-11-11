<?php

declare(strict_types=1);

namespace App\Modules\Attendance;

use App\Modules\Attendance\Console\CloseAttendanceDaysCommand;
use App\Modules\Attendance\Console\SimulatePunchesCommand;
use App\Modules\Attendance\Events\AttendanceDaysChanged;
use App\Modules\Attendance\Listeners\BroadcastTodayChanges;
use App\Modules\Attendance\Listeners\RecalculateOnLeaveChange;
use App\Modules\Attendance\Listeners\RecalculateOnPeriodUnlock;
use App\Modules\Attendance\Listeners\RecalculateOnScheduleChange;
use App\Modules\Attendance\Models\EmployeePunchIdentifier;
use App\Modules\Attendance\Models\PunchCorrection;
use App\Modules\Attendance\Models\PunchSource;
use App\Modules\Attendance\Services\AttendanceCalculator;
use App\Modules\Attendance\Services\AttendanceQuery;
use App\Modules\Attendance\Services\DayEvaluator;
use App\Modules\Attendance\Services\OrphanPunchService;
use App\Modules\Attendance\Services\PunchCorrectionService;
use App\Modules\Attendance\Services\PunchIdentifierService;
use App\Modules\Attendance\Services\PunchIngestor;
use App\Modules\Attendance\Services\PunchProcessor;
use App\Modules\Attendance\Services\PunchSourceService;
use App\Modules\Attendance\Services\RawPunchQuery;
use App\Modules\Attendance\Support\TodayChannel;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Enums\SettingType;
use App\Modules\Core\Events\LeaveChanged;
use App\Modules\Core\Events\PayrollPeriodUnlocked;
use App\Modules\Core\Permissions\PermissionCatalog;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Core\Settings\SettingsRegistry;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Scheduling\Events\ScheduleChanged;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Punch ingestion and the attendance engine.
 *
 *   device/integration --POST /punches--> raw_punches (append-only, 202)
 *     --ProcessPunches--> punch_matches / orphan_punches
 *     --AttendanceCalculator (ScheduleResolver + DayEvaluator)--> attendance_days
 *
 * ScheduleChanged, approved corrections, linked orphans and the scheduled
 * attendance:close-days command trigger recalculation too.
 */
final class AttendanceServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        foreach ([
            PunchSourceService::class, PunchIngestor::class, PunchProcessor::class, DayEvaluator::class,
            AttendanceCalculator::class, AttendanceQuery::class, RawPunchQuery::class, OrphanPunchService::class,
            PunchIdentifierService::class, PunchCorrectionService::class,
        ] as $service) {
            $this->app->singleton($service);
        }
    }

    public function boot(): void
    {
        $this->bootModule();

        Relation::enforceMorphMap([
            'punch_source' => PunchSource::class,
            'punch_identifier' => EmployeePunchIdentifier::class,
            'punch_correction' => PunchCorrection::class,
        ]);

        Event::listen(ScheduleChanged::class, RecalculateOnScheduleChange::class);
        Event::listen(LeaveChanged::class, RecalculateOnLeaveChange::class);
        Event::listen(PayrollPeriodUnlocked::class, RecalculateOnPeriodUnlock::class);
        Event::listen(AttendanceDaysChanged::class, BroadcastTodayChanges::class);

        TodayChannel::register();

        if ($this->app->runningInConsole()) {
            $this->commands([SimulatePunchesCommand::class, CloseAttendanceDaysCommand::class]);
        }

        // Per tenant host and source key prefix (or IP when there is no usable key).
        RateLimiter::for('punches', function (Request $request): Limit {
            $key = preg_match('/^ams_([a-z0-9]{10})_/', (string) $request->bearerToken(), $m) ? $m[1] : $request->ip();

            return Limit::perMinute((int) config('ams.rate_limits.punches_per_minute'))->by($request->getHost().'|'.$key);
        });

        $this->registerPermissions();
        $this->registerSettings();
    }

    private function registerPermissions(): void
    {
        $catalog = $this->app->make(PermissionCatalog::class);

        $catalog->register('attendance.view', [RoleName::HrManager, RoleName::DepartmentManager]);
        $catalog->register('attendance.manage', [RoleName::HrManager]);
        $catalog->register('punches.view', [RoleName::HrManager]);
        $catalog->register('punches.manage', [RoleName::HrManager]);
        $catalog->register('punch-sources.manage');
        $catalog->register('corrections.request', [RoleName::HrManager, RoleName::DepartmentManager, RoleName::Employee]);
        $catalog->register('corrections.approve', [RoleName::HrManager, RoleName::DepartmentManager]);
    }

    private function registerSettings(): void
    {
        $this->app->make(SettingsRegistry::class)->register(
            new SettingDefinition(
                key: 'attendance.debounce_seconds',
                type: SettingType::Integer,
                default: 60,
                label: 'Ignore repeat punches within (seconds)',
                description: 'A punch this soon after the previous one counts once (double taps).',
                rules: ['integer', 'min:0', 'max:3600'],
            ),
            new SettingDefinition(
                key: 'attendance.rounding_minutes',
                type: SettingType::Integer,
                default: 0,
                label: 'Round worked time down to (minutes)',
                description: '0 keeps exact minutes; 15 rounds 7 h 52 min down to 7 h 45 min.',
                rules: ['integer', 'min:0', 'max:60'],
            ),
            new SettingDefinition(
                key: 'attendance.use_punch_direction',
                type: SettingType::Boolean,
                default: false,
                label: 'Trust the device in/out direction',
                description: 'Off: the first punch is the check-in and the last the check-out.',
            ),
            new SettingDefinition(
                key: 'attendance.backlog_days',
                type: SettingType::Integer,
                default: 31,
                label: 'Accept late-arriving punches for (days)',
                description: 'Older punches (a device offline for long) are kept but do not change attendance.',
                rules: ['integer', 'min:1', 'max:366'],
            ),
            new SettingDefinition(
                key: 'attendance.max_batch_size',
                type: SettingType::Integer,
                default: 500,
                label: 'Largest punch batch (punches)',
                description: 'POST /punches/batch refuses larger batches.',
                rules: ['integer', 'min:1', 'max:1000'],
            ),
        );
    }
}
