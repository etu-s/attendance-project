<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Core\Support\Search;
use App\Modules\Core\Support\TenantClock;
use App\Modules\Organization\Models\Group;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class GroupService
{
    public function __construct(
        private readonly ManagerAssignments $managers,
        private readonly TenantClock $clock,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{0: string, 1: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, Group>
     */
    public function paginate(array $filters, ?string $search, array $sort, int $perPage): LengthAwarePaginator
    {
        $today = $this->clock->today()->format(LocalDate::FORMAT);

        $query = Group::query()->withCount(['memberships as members_count' => fn (Builder $q) => $q
            ->where('effective_from', '<=', $today)
            ->where(fn (Builder $w) => $w->whereNull('effective_to')->orWhere('effective_to', '>=', $today))
            ->whereHas('employee')]);

        if (isset($filters['is_active'])) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        Search::apply($query, ['name', 'code'], $search);

        [$column, $direction] = $sort;
        $page = $query->orderBy($column, $direction)->orderBy('id')->paginate($perPage)->withQueryString();

        $this->attachManagers(collect($page->items()));

        return $page;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Group
    {
        return Group::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Group $group, array $attributes): Group
    {
        $group->update($attributes);

        return $group;
    }

    public function delete(Group $group): void
    {
        if ($group->memberships()->exists()) {
            throw new BusinessRuleException('Employees have been members of this group. Deactivate it instead.', 'group_in_use', 409);
        }

        $group->delete();
    }

    /**
     * @param  list<int>  $userIds
     */
    public function setManagers(Group $group, array $userIds): Group
    {
        $this->managers->sync($group, 'group_managers', 'group_id', $userIds);
        $this->attachManagers(collect([$group]));

        return $group;
    }

    /**
     * @param  Collection<int, Group>  $groups
     */
    public function attachManagers(Collection $groups): void
    {
        $map = $this->managers->forOwners('group_managers', 'group_id', $groups->pluck('id')->all());

        $groups->each(fn (Group $g) => $g->setRelation('managerSummaries', collect($map[$g->id] ?? [])));
    }
}
