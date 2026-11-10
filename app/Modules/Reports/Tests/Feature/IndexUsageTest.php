<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
 * The hot attendance, report and export queries must be served by an index
 * (CLAUDE.md: index attendance queries). SQLite's plan says "SEARCH … USING
 * INDEX" (or COVERING INDEX); a bare "SCAN <table>" reads the whole table.
 */
function queryPlan(string $sql, array $bindings): string
{
    return collect(DB::select('EXPLAIN QUERY PLAN '.$sql, $bindings))->pluck('detail')->implode(' | ');
}

it('serves the hot queries from indexes', function (string $sql, array $bindings, string $index): void {
    $tenant = $this->createTenant('acme');

    $tenant->run(function () use ($sql, $bindings, $index): void {
        $plan = queryPlan($sql, $bindings);

        expect($plan)->toContain('USING')->toContain($index)
            ->and($plan)->not->toMatch('/SCAN \w+(?! USING)( |$)/');
    });
})->with([
    'attendance by date and status (today, summaries)' => [
        'select status, count(*) from attendance_days where work_date = ? and status in (?, ?) group by status',
        ['2026-03-02', 'late', 'absent'], 'attendance_days_work_date_status_index',
    ],
    'one employee\'s month' => [
        'select * from attendance_days where employee_id = ? and work_date >= ? and work_date <= ?',
        [1, '2026-03-01', '2026-03-31'], 'attendance_days_employee_id_work_date_unique',
    ],
    'rollup over a range' => [
        'select sum(days) from attendance_daily_stats where work_date >= ? and work_date <= ?',
        ['2026-03-01', '2026-03-31'], 'attendance_daily_stats_work_date_department_id_location_id_unique',
    ],
    'rollup for departments' => [
        'select sum(days) from attendance_daily_stats where department_id in (?, ?) and work_date >= ? and work_date <= ?',
        [1, 2, '2026-03-01', '2026-03-31'], 'attendance_daily_stats_department_id_work_date_index',
    ],
    'overtime inbox' => [
        'select * from overtime_records where status = ? and work_date >= ? order by work_date desc',
        ['pending', '2026-03-01'], 'overtime_records_status_work_date_index',
    ],
    'leave taken per employee' => [
        'select * from leave_request_days where employee_id = ? and date >= ? and date <= ?',
        [1, '2026-03-01', '2026-03-31'], 'leave_request_days_employee_id_date_index',
    ],
    'a user\'s exports' => [
        'select * from report_exports where requested_by = ? order by created_at desc',
        [1], 'report_exports_requested_by_created_at_index',
    ],
    'expired exports to prune' => [
        'select * from report_exports where status = ? and expires_at <= ?',
        ['completed', '2026-03-01 00:00:00'], 'report_exports_status_expires_at_index',
    ],
]);
