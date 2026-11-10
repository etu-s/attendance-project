<?php

declare(strict_types=1);

namespace App\Modules\Reports\Services;

use App\Modules\Attendance\Services\AttendanceQuery;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Services\DepartmentTree;
use App\Modules\Organization\Services\EmployeeVisibility;
use App\Modules\Reports\Models\AttendanceDailyStat;
use App\Modules\Reports\Support\ReportParams;

/**
 * Attendance aggregates for the dashboard, from the daily rollup when the
 * viewer sees everyone (HR, Tenant Admin) and the filters are ones the
 * rollup has (department, location), otherwise live over attendance days,
 * scoped to the viewer (managers, group filter, search). Both paths return
 * the same columns, defined once in AttendanceQuery::aggregateColumns().
 */
final class StatsReader
{
    public function __construct(
        private readonly AttendanceQuery $attendance,
        private readonly EmployeeVisibility $visibility,
        private readonly DepartmentTree $tree,
    ) {}

    public function usesRollup(ReportParams $params, User $viewer): bool
    {
        return $params->groupId === null && $params->search === null && $this->visibility->seesEveryone($viewer);
    }

    /**
     * @param  'work_date'|'department_id'|'location_id'|null  $groupBy
     * @return list<array<string, int|string|null>>
     */
    public function aggregate(ReportParams $params, User $viewer, ?string $groupBy = null): array
    {
        if (! $this->usesRollup($params, $viewer)) {
            return $this->attendance->aggregate($params->filters(), $params->search, $viewer, $groupBy);
        }

        $query = AttendanceDailyStat::query()->toBase()
            ->where('work_date', '>=', $params->from->format(LocalDate::FORMAT))
            ->where('work_date', '<=', $params->to->format(LocalDate::FORMAT));

        if ($params->departmentId !== null) {
            $query->whereIn('department_id', $this->tree->withDescendants([$params->departmentId]));
        }
        if ($params->locationId !== null) {
            $query->where('location_id', $params->locationId);
        }
        if ($groupBy !== null) {
            $query->addSelect($groupBy)->groupBy($groupBy)->orderBy($groupBy);
        }
        foreach (array_keys(AttendanceQuery::aggregateColumns()) as $alias) {
            $query->selectRaw("coalesce(sum({$alias}), 0) as {$alias}");
        }

        return array_map(function (object $row) use ($groupBy): array {
            $row = array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, (array) $row);
            if ($groupBy === 'work_date') {
                $row['work_date'] = substr((string) $row['work_date'], 0, 10);
            } elseif ($groupBy !== null) {
                // The rollup stores "none" as 0.
                $row[$groupBy] = $row[$groupBy] === 0 ? null : $row[$groupBy];
            }

            return $row;
        }, $query->get()->all());
    }
}
