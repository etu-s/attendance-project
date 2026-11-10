<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Services\EmployeeVisibility;
use App\Modules\Overtime\Services\OvertimeQuery;
use App\Modules\Reports\Support\EmployeeLookup;
use App\Modules\Reports\Support\Kpis;
use App\Modules\Reports\Support\ReportParams;
use Illuminate\Support\Facades\Cache;

/**
 * The analytics dashboard: KPIs, a daily trend, the status mix and a
 * per-department table, for one range and filters.
 *
 * Cached briefly (ams.reports.dashboard_cache_seconds). The cache store is
 * tenant-tagged by the tenancy cache bootstrapper; the key also carries the
 * viewer's scope, because managers see different numbers than HR.
 */
final class DashboardService
{
    public function __construct(
        private readonly StatsReader $stats,
        private readonly OvertimeQuery $overtime,
        private readonly EmployeeVisibility $visibility,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(ReportParams $params, User $viewer): array
    {
        $scope = $this->visibility->seesEveryone($viewer) ? 'all' : 'user:'.$viewer->id;
        $key = 'reports:dashboard:'.sha1($scope.'|'.json_encode($params->toArray()));

        return Cache::remember($key, (int) config('ams.reports.dashboard_cache_seconds'), fn () => $this->compute($params, $viewer));
    }

    /**
     * @return array<string, mixed>
     */
    private function compute(ReportParams $params, User $viewer): array
    {
        $totals = $this->stats->aggregate($params, $viewer)[0] ?? [];
        $kpis = Kpis::from($totals);

        $overtime = $this->overtime->totals(
            array_intersect_key($params->filters(), array_flip(['from', 'to', 'department_id'])),
            $viewer,
        );

        $statuses = [];
        foreach (['present', 'late', 'early_leave', 'late_early', 'incomplete', 'absent', 'in_progress', 'on_leave', 'holiday', 'rest', 'day_off', 'unscheduled'] as $status) {
            $statuses[$status] = (int) ($totals[$status] ?? 0);
        }

        return [
            'range' => [
                'from' => $params->from->format(LocalDate::FORMAT),
                'to' => $params->to->format(LocalDate::FORMAT),
            ],
            'source' => $this->stats->usesRollup($params, $viewer) ? 'rollup' : 'live',
            'kpis' => [
                ...$kpis,
                'overtime_approved_minutes' => $overtime['approved_minutes'],
                'overtime_weighted_minutes' => $overtime['approved_weighted_minutes'],
                'overtime_pending_minutes' => $overtime['pending_minutes'],
                'overtime_pending_count' => $overtime['pending_count'],
            ],
            'statuses' => $statuses,
            'trend' => $this->trend($params, $viewer),
            'departments' => $this->departments($params, $viewer),
        ];
    }

    /**
     * Every date of the range, zero-filled.
     *
     * @return list<array<string, mixed>>
     */
    private function trend(ReportParams $params, User $viewer): array
    {
        $byDate = [];
        foreach ($this->stats->aggregate($params, $viewer, 'work_date') as $row) {
            $byDate[(string) $row['work_date']] = $row;
        }

        $trend = [];
        for ($date = $params->from; $date->lessThanOrEqualTo($params->to); $date = $date->addDay()) {
            $key = $date->format(LocalDate::FORMAT);
            $kpis = Kpis::from($byDate[$key] ?? []);
            $trend[] = [
                'date' => $key,
                'on_time' => $kpis['attended_days'] - $kpis['late_days'],
                'late' => $kpis['late_days'],
                'absent' => $kpis['absent_days'],
                'on_leave' => $kpis['leave_days'],
                'attendance_rate' => $kpis['attendance_rate'],
            ];
        }

        return $trend;
    }

    /**
     * One row per department recorded on the days (sub-departments are their
     * own rows), most working days first.
     *
     * @return list<array<string, mixed>>
     */
    private function departments(ReportParams $params, User $viewer): array
    {
        $rows = $this->stats->aggregate($params, $viewer, 'department_id');
        $names = EmployeeLookup::departments(array_map(fn (array $row) => (int) $row['department_id'], $rows));

        $departments = array_map(function (array $row) use ($names): array {
            $kpis = Kpis::from($row);

            return [
                'department' => $row['department_id'] === null ? null : ($names[(int) $row['department_id']] ?? null),
                'working_days' => $kpis['working_days'],
                'attended_days' => $kpis['attended_days'],
                'absent_days' => $kpis['absent_days'],
                'late_days' => $kpis['late_days'],
                'leave_days' => $kpis['leave_days'],
                'worked_minutes' => $kpis['worked_minutes'],
                'attendance_rate' => $kpis['attendance_rate'],
                'punctuality_rate' => $kpis['punctuality_rate'],
            ];
        }, $rows);

        usort($departments, fn (array $a, array $b) => $b['working_days'] <=> $a['working_days']);

        return $departments;
    }
}
