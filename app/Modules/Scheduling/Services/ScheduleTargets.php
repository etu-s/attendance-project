<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Services;

use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\Group;
use App\Modules\Scheduling\Enums\ScheduleTarget;
use App\Modules\Scheduling\Models\ScheduleAssignment;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Names and existence checks for schedule targets (Organization rows).
 */
final class ScheduleTargets
{
    public function assertExists(ScheduleTarget $type, ?int $id): void
    {
        $exists = match ($type) {
            ScheduleTarget::Organization => $id === null,
            ScheduleTarget::Department => $id !== null && Department::query()->whereKey($id)->exists(),
            ScheduleTarget::Group => $id !== null && Group::query()->whereKey($id)->exists(),
            ScheduleTarget::Employee => $id !== null && Employee::query()->whereKey($id)->exists(),
        };

        if (! $exists) {
            throw ValidationException::withMessages(['target_id' => $type === ScheduleTarget::Organization
                ? 'The organization default has no target.'
                : "Choose an existing {$type->value}."]);
        }
    }

    /**
     * Display names for many targets at once.
     *
     * @param  Collection<int, ScheduleAssignment>  $assignments
     * @return array<string, string> target key ("department:3") => name
     */
    public function names(Collection $assignments): array
    {
        $ids = fn (ScheduleTarget $type) => $assignments->where('target_type', $type)->pluck('target_id')->unique()->values()->all();
        $names = ['organization:' => 'Organization default'];

        foreach (Department::query()->whereKey($ids(ScheduleTarget::Department))->pluck('name', 'id') as $id => $name) {
            $names["department:{$id}"] = (string) $name;
        }
        foreach (Group::query()->whereKey($ids(ScheduleTarget::Group))->pluck('name', 'id') as $id => $name) {
            $names["group:{$id}"] = (string) $name;
        }
        foreach (Employee::withTrashed()->whereKey($ids(ScheduleTarget::Employee))->get(['id', 'first_name', 'last_name']) as $employee) {
            $names["employee:{$employee->id}"] = $employee->fullName();
        }

        return $names;
    }
}
