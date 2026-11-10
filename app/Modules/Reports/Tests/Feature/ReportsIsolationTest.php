<?php

declare(strict_types=1);

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Core\Tenancy\TenantSqliteDatabaseManager;
use App\Modules\Reports\Models\AttendanceDailyStat;
use App\Modules\Reports\Models\ReportExport;
use App\Modules\Reports\Services\DailyStatsBuilder;
use App\Modules\Reports\Tests\Support\ReportsTesting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class, ReportsTesting::class);

afterEach(function (): void {
    $this->removeTenantStorage();
    if (isset($this->acme)) {
        $this->acme->run(fn () => File::deleteDirectory(storage_path()));
    }
});

it('keeps tenant B\'s rollup, exports and files out of tenant A', function (): void {
    $this->reportsWorkspace();
    $this->acme = $this->tenant;
    $acmeExport = $this->actingAsTenantUser($this->hr)
        ->postJson($this->tenantUrl($this->acme, 'reports/exports'), ['report' => 'attendance-summary', 'format' => 'csv', 'from' => '2026-03-01', 'to' => '2026-03-31'])
        ->json('data.id');
    $acmePath = $this->acme->run(fn () => ReportExport::query()->findOrFail($acmeExport)->path);

    $this->leaveWorkspace('globex');
    $globexHr = $this->hr;
    $this->tenant->run(function (): void {
        AttendanceDay::factory()->absent()->create(['employee_id' => $this->employee->id, 'work_date' => '2026-03-16', 'worked_minutes' => 0, 'scheduled_minutes' => 777]);
        app(DailyStatsBuilder::class)->rebuild(CarbonImmutable::parse('2026-03-16'), CarbonImmutable::parse('2026-03-16'));
    });
    $this->actingAsTenantUser($globexHr)
        ->postJson($this->tenantUrl($this->tenant, 'reports/exports'), ['report' => 'leave', 'format' => 'csv', 'from' => '2026-03-01', 'to' => '2026-03-31'])
        ->assertStatus(202);

    // Globex sees only its own data, and cannot reach Acme's export by id.
    $this->getJson($this->tenantUrl($this->tenant, 'reports/dashboard?from=2026-03-02&to=2026-03-20'))
        ->assertJsonPath('data.kpis.working_days', 1)->assertJsonPath('data.kpis.absent_days', 1);
    $this->tenant->run(fn () => expect(AttendanceDailyStat::query()->sum('days'))->toBe(1)
        ->and(Storage::disk('local')->exists((string) $acmePath))->toBeFalse());

    $pdo = new PDO('sqlite:'.app(TenantSqliteDatabaseManager::class)->path($this->acme->database()->getName()));
    expect((int) $pdo->query('select count(*) from attendance_daily_stats where scheduled_minutes >= 777')->fetchColumn())->toBe(0)
        ->and((int) $pdo->query("select count(*) from attendance_daily_stats where work_date = '2026-03-16'")->fetchColumn())->toBe(0)
        ->and((int) $pdo->query("select count(*) from report_exports where report = 'leave'")->fetchColumn())->toBe(0)
        ->and((int) $pdo->query('select count(*) from report_exports')->fetchColumn())->toBe(1);
    $pdo = null;

    $this->acme->run(fn () => expect(Storage::disk('local')->exists((string) $acmePath))->toBeTrue());
});
