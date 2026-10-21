<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Models\Department;
use Illuminate\Support\Collection;

/**
 * Tree operations over departments. Department tables are small, so the
 * parent map is loaded once per call rather than walked with recursive SQL.
 */
final class DepartmentTree
{
    /**
     * @return array<int, int|null> department id => parent id
     */
    private function parentMap(): array
    {
        return Department::query()
            ->pluck('parent_id', 'id')
            ->map(fn ($parent) => $parent === null ? null : (int) $parent)
            ->all();
    }

    /**
     * The given departments plus everything below them.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function withDescendants(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $children = [];
        foreach ($this->parentMap() as $id => $parent) {
            if ($parent !== null) {
                $children[$parent][] = $id;
            }
        }

        $result = [];
        $stack = $ids;
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($result[$id])) {
                continue;
            }
            $result[$id] = true;
            foreach ($children[$id] ?? [] as $child) {
                $stack[] = $child;
            }
        }

        return array_keys($result);
    }

    /**
     * Every department's chain from itself up to the root (nearest first), in
     * one query; used for "inherit from the nearest ancestor" rules.
     *
     * @return array<int, list<int>>
     */
    public function ancestorChains(): array
    {
        $parents = $this->parentMap();
        $chains = [];

        foreach (array_keys($parents) as $id) {
            $chain = [];
            $current = $id;
            while ($current !== null && ! in_array($current, $chain, true)) {
                $chain[] = $current;
                $current = $parents[$current] ?? null;
            }
            $chains[$id] = $chain;
        }

        return $chains;
    }

    /**
     * True when making $newParentId the parent of $departmentId would put the
     * department under itself (directly or through its descendants).
     */
    public function wouldCreateCycle(int $departmentId, ?int $newParentId): bool
    {
        $parents = $this->parentMap();
        $current = $newParentId;
        $guard = 0;

        while ($current !== null && $guard++ <= count($parents)) {
            if ($current === $departmentId) {
                return true;
            }
            $current = $parents[$current] ?? null;
        }

        return false;
    }

    /**
     * Departments in depth-first tree order, each with `depth` and `path`
     * (e.g. "Operations / Warehouse") set as attributes for display. A row
     * whose parent is not in the collection (e.g. filtered out) is shown at
     * the top level; its real parent_id is left untouched.
     *
     * @param  Collection<int, Department>  $departments
     * @return Collection<int, Department>
     */
    public function ordered(Collection $departments): Collection
    {
        $present = $departments->pluck('id')->flip();
        $byParent = $departments->groupBy(
            fn (Department $d) => $d->parent_id !== null && $present->has($d->parent_id) ? $d->parent_id : 0,
        );
        $ordered = collect();

        $walk = function (int $parentId, int $depth, string $prefix) use (&$walk, $byParent, $ordered): void {
            foreach (($byParent[$parentId] ?? collect())->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE) as $department) {
                $path = $prefix === '' ? $department->name : $prefix.' / '.$department->name;
                $department->setAttribute('depth', $depth);
                $department->setAttribute('path', $path);
                $ordered->push($department);
                $walk($department->id, $depth + 1, $path);
            }
        };

        $walk(0, 0, '');

        return $ordered;
    }
}
