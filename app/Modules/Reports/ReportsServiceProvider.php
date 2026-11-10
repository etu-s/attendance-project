<?php

declare(strict_types=1);

namespace App\Modules\Reports;

use App\Modules\Attendance\Events\AttendanceDaysChanged;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Permissions\PermissionCatalog;
use App\Modules\Core\Support\ModuleServiceProvider;
use App\Modules\Reports\Console\PruneExportsCommand;
use App\Modules\Reports\Console\RebuildStatsCommand;
use App\Modules\Reports\Listeners\RefreshDailyStats;
use App\Modules\Reports\Models\ReportExport;
use App\Modules\Reports\Reports\ReportRegistry;
use App\Modules\Reports\Services\DailyStatsBuilder;
use App\Modules\Reports\Services\DashboardService;
use App\Modules\Reports\Services\ReportExportService;
use App\Modules\Reports\Services\StatsReader;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;

/**
 * Reports & Analytics: a daily attendance rollup (kept in sync by the
 * attendance writer), a cached dashboard, tabular reports read through the
 * owning modules' services, and queued CSV/XLSX exports.
 */
final class ReportsServiceProvider extends ModuleServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DailyStatsBuilder::class);
        $this->app->singleton(ReportRegistry::class);
        $this->app->scoped(StatsReader::class);
        $this->app->scoped(DashboardService::class);
        $this->app->scoped(ReportExportService::class);
    }

    public function boot(): void
    {
        $this->bootModule();

        Relation::enforceMorphMap(['report_export' => ReportExport::class]);

        Event::listen(AttendanceDaysChanged::class, RefreshDailyStats::class);

        $catalog = $this->app->make(PermissionCatalog::class);
        $catalog->register('reports.view', [RoleName::HrManager, RoleName::DepartmentManager]);
        $catalog->register('reports.export', [RoleName::HrManager, RoleName::DepartmentManager]);

        if ($this->app->runningInConsole()) {
            $this->commands([RebuildStatsCommand::class, PruneExportsCommand::class]);
        }
    }
}
