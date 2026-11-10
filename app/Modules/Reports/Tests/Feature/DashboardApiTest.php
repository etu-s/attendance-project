<?php

declare(strict_types=1);

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Organization\Models\Group;
use App\Modules\Overtime\Enums\OvertimeStatus;
use App\Modules\Overtime\Models\OvertimeRecord;
use App\Modules\Reports\Services\DailyStatsBuilder;
use App\Modules\Reports\Services\DashboardService;
use App\Modules\Reports\Support\ReportParams;
use App\Modules\Reports\Tests\Support\ReportsTesting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class, ReportsTesting::class);

beforeEach(function (): void {
    config(['ams.reports.dashboard_cache_seconds' => 0]);
    $this->reportsWorkspace();
    $this->week = 'reports/dashboard?from=2026-03-02&to=2026-03-08';
});

it('gives HR the KPIs, trend, status mix and departments from the rollup', function (): void {
    $this->tenant->run(fn () => OvertimeRecord::factory()->create([
        'employee_id' => $this->employee->id, 'status' => OvertimeStatus::Approved,
        'approved_minutes' => 60, 'approved_weighted_minutes' => 90,
    ]));

    $response = $this->actingAsTenantUser($this->hr)->getJson($this->tenantUrl($this->tenant, $this->week))->assertOk();

    $response->assertJsonPath('data.source', 'rollup')
        ->assertJsonPath('data.range', ['from' => '2026-03-02', 'to' => '2026-03-08'])
        ->assertJsonPath('data.kpis.working_days', 9)
        ->assertJsonPath('data.kpis.attended_days', 7)
        ->assertJsonPath('data.kpis.absent_days', 2)
        ->assertJsonPath('data.kpis.late_days', 2)
        ->assertJsonPath('data.kpis.late_minutes', 30)
        ->assertJsonPath('data.kpis.early_leave_days', 2)
        ->assertJsonPath('data.kpis.leave_days', 1)
        ->assertJsonPath('data.kpis.attendance_rate', 77.8)
        ->assertJsonPath('data.kpis.punctuality_rate', 71.4)
        ->assertJsonPath('data.kpis.overtime_approved_minutes', 60)
        ->assertJsonPath('data.kpis.overtime_weighted_minutes', 90)
        ->assertJsonPath('data.statuses.present', 4)
        ->assertJsonPath('data.statuses.rest', 1)
        ->assertJsonCount(7, 'data.trend')
        ->assertJsonPath('data.trend.0', ['date' => '2026-03-02', 'on_time' => 2, 'late' => 0, 'absent' => 0, 'on_leave' => 0, 'attendance_rate' => 100])
        ->assertJsonPath('data.trend.3.absent', 1)
        ->assertJsonPath('data.trend.3.on_leave', 1)
        ->assertJsonPath('data.trend.6.attendance_rate', null)
        ->assertJsonCount(2, 'data.departments');

    expect(collect($response->json('data.departments'))->firstWhere('department.name', 'Warehouse'))
        ->toMatchArray(['working_days' => 5, 'attended_days' => 4, 'attendance_rate' => 80, 'punctuality_rate' => 75]);
});

it('gives a department manager live numbers for their own people only', function (): void {
    $this->actingAsTenantUser($this->manager)->getJson($this->tenantUrl($this->tenant, $this->week))
        ->assertOk()
        ->assertJsonPath('data.source', 'live')
        ->assertJsonPath('data.kpis.working_days', 4)
        ->assertJsonPath('data.kpis.attendance_rate', 75)
        ->assertJsonPath('data.kpis.punctuality_rate', 66.7)
        ->assertJsonCount(1, 'data.departments')
        ->assertJsonPath('data.departments.0.department.id', $this->department->id);

    // A filter cannot widen what the manager sees.
    $this->getJson($this->tenantUrl($this->tenant, $this->week.'&department_id='.$this->otherDepartment->id))
        ->assertOk()->assertJsonPath('data.kpis.working_days', 0)->assertJsonPath('data.kpis.attendance_rate', null);
});

it('filters by department and location on the rollup, and by group live', function (): void {
    $this->actingAsTenantUser($this->hr);
    $this->getJson($this->tenantUrl($this->tenant, $this->week.'&department_id='.$this->otherDepartment->id))
        ->assertJsonPath('data.source', 'rollup')->assertJsonPath('data.kpis.working_days', 5);
    $this->getJson($this->tenantUrl($this->tenant, $this->week.'&location_id=999'))
        ->assertJsonPath('data.kpis.working_days', 0);

    $group = $this->tenant->run(function () {
        $group = Group::factory()->create();
        $this->other->memberships()->create(['group_id' => $group->id, 'effective_from' => '2026-01-01']);

        return $group;
    });
    $this->getJson($this->tenantUrl($this->tenant, $this->week.'&group_id='.$group->id))
        ->assertJsonPath('data.source', 'live')->assertJsonPath('data.kpis.working_days', 5);
});

// Within one tenant context: the test cache store (array) is rebuilt by the
// tenancy cache bootstrapper on every initialization, unlike Redis.
it('caches the result per viewer scope, for a limited time', function (): void {
    config(['ams.reports.dashboard_cache_seconds' => 60]);

    $this->tenant->run(function (): void {
        $dashboard = app(DashboardService::class);
        $params = new ReportParams(CarbonImmutable::parse('2026-03-02'), CarbonImmutable::parse('2026-03-08'));
        $days = fn ($user) => $dashboard->build($params, $user)['kpis']['working_days'];

        expect($days($this->hr))->toBe(9)->and($days($this->manager))->toBe(4);

        AttendanceDay::query()->where('work_date', '2026-03-02')->delete();
        app(DailyStatsBuilder::class)->refresh(['2026-03-02']);

        expect($days($this->hr))->toBe(9)->and($days($this->manager))->toBe(4);

        $this->travel(61)->seconds();
        expect($days($this->hr))->toBe(7)->and($days($this->manager))->toBe(3);
    });
});

it('validates the range and requires reports.view', function (): void {
    $this->actingAsTenantUser($this->hr);
    $this->getJson($this->tenantUrl($this->tenant, 'reports/dashboard'))->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
    $this->getJson($this->tenantUrl($this->tenant, 'reports/dashboard?from=2026-03-08&to=2026-03-02'))->assertJsonValidationErrors('to');
    $this->getJson($this->tenantUrl($this->tenant, 'reports/dashboard?from=2026-01-01&to=2026-04-03'))->assertJsonValidationErrors('to');
    $this->getJson($this->tenantUrl($this->tenant, 'reports/dashboard?from=2026-01-01&to=2026-04-02'))->assertOk();

    $this->actingAsTenantUser($this->worker)->getJson($this->tenantUrl($this->tenant, $this->week))->assertForbidden();
});
