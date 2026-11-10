<?php

declare(strict_types=1);

namespace App\Modules\Reports\Support;

use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\Location;

/**
 * Display names for report rows, loaded once per page (no N+1). Includes
 * soft-deleted employees: history stays readable after a removal.
 */
final class EmployeeLookup
{
    /**
     * @param  list<int>  $ids
     * @return array<int, array{id: int, full_name: string, employee_code: string}>
     */
    public static function employees(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $map = [];
        foreach (Employee::withTrashed()->whereKey($ids)->get(['id', 'first_name', 'last_name', 'employee_code']) as $employee) {
            $map[$employee->id] = ['id' => $employee->id, 'full_name' => $employee->fullName(), 'employee_code' => $employee->employee_code];
        }

        return $map;
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{id: int, name: string, code: string}>
     */
    public static function departments(array $ids): array
    {
        $ids = array_values(array_filter($ids));

        return $ids === [] ? [] : Department::query()->whereKey($ids)->get(['id', 'name', 'code'])
            ->mapWithKeys(fn (Department $d) => [$d->id => ['id' => $d->id, 'name' => $d->name, 'code' => $d->code]])
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, array{id: int, name: string, code: string}>
     */
    public static function locations(array $ids): array
    {
        $ids = array_values(array_filter($ids));

        return $ids === [] ? [] : Location::query()->whereKey($ids)->get(['id', 'name', 'code'])
            ->mapWithKeys(fn (Location $l) => [$l->id => ['id' => $l->id, 'name' => $l->name, 'code' => $l->code]])
            ->all();
    }
}
