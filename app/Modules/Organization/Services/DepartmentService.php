<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Core\Support\TenantClock;
use App\Modules\Organization\Models\Department;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class DepartmentService
{
    public function __construct(
        private readonly DepartmentTree $tree,
        private readonly ManagerAssignments $managers,
        private readonly TenantClock $clock,
    ) {}

    /**
     * Every department in tree order with depth/path, current headcount and
     * managers. Not paginated: it is a tree, and department counts are small.
     *
     * @return Collection<int, Department>
     */
    public function tree(?bool $active = null): Collection
    {
        $today = $this->clock->today()->format(LocalDate::FORMAT);

        $departments = Department::query()
            ->when($active !== null, fn (Builder $q) => $q->where('is_active', $active))
            ->withCount(['assignments as employees_count' => fn (Builder $q) => $q
                ->where('effective_from', '<=', $today)
                ->where(fn (Builder $w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
                ->whereHas('employee')])
            ->get();

        $this->attachManagers($departments);

        return $this->tree->ordered($departments);
    }

    /**
     * @param  array{name: string, code: string, parent_id?: int|null, is_active?: bool}  $attributes
     */
    public function create(array $attributes): Department
    {
        return Department::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Department $department, array $attributes): Department
    {
        if (array_key_exists('parent_id', $attributes)) {
            $parent = $attributes['parent_id'] === null ? null : (int) $attributes['parent_id'];

            if ($parent === $department->id || $this->tree->wouldCreateCycle($department->id, $parent)) {
                throw new BusinessRuleException('A department cannot be placed under itself or one of its sub-departments.', 'department_cycle');
            }
        }

        $department->update($attributes);

        return $department;
    }

    public function delete(Department $department): void
    {
        if ($department->children()->exists()) {
            throw new BusinessRuleException('Move or delete its sub-departments first.', 'department_has_children', 409);
        }

        if ($department->assignments()->exists()) {
            throw new BusinessRuleException('Employees have been assigned to this department. Deactivate it instead.', 'department_in_use', 409);
        }

        $department->delete();
    }

    /**
     * @param  list<int>  $userIds
     */
    public function setManagers(Department $department, array $userIds): Department
    {
        $this->managers->sync($department, 'department_managers', 'department_id', $userIds);
        $this->attachManagers(collect([$department]));

        return $department;
    }

    /**
     * @param  Collection<int, Department>  $departments
     */
    public function attachManagers(Collection $departments): void
    {
        $map = $this->managers->forOwners('department_managers', 'department_id', $departments->pluck('id')->all());

        $departments->each(fn (Department $d) => $d->setRelation('managerSummaries', collect($map[$d->id] ?? [])));
    }
}
