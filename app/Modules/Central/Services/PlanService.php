<?php

declare(strict_types=1);

namespace App\Modules\Central\Services;

use App\Modules\Central\Models\Plan;
use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Core\Support\Search;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class PlanService
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  array{0: string, 1: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, Plan>
     */
    public function paginate(array $filters, ?string $search, array $sort, int $perPage): LengthAwarePaginator
    {
        $query = Plan::query()->withCount('tenants');

        if (isset($filters['is_active'])) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        Search::apply($query, ['name', 'slug'], $search);

        [$column, $direction] = $sort;

        return $query->orderBy($column, $direction)->orderBy('id')->paginate($perPage)->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Plan
    {
        return Plan::query()->create($attributes)->loadCount('tenants');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Plan $plan, array $attributes): Plan
    {
        $plan->update($attributes);

        return $plan->loadCount('tenants');
    }

    public function delete(Plan $plan): void
    {
        // The FK restricts this too; checking first gives a readable error instead of a constraint failure.
        if ($plan->tenants()->exists()) {
            throw new BusinessRuleException('This plan is assigned to tenants. Move them to another plan or deactivate it instead.', 'plan_in_use', 409);
        }

        $plan->delete();
    }
}
