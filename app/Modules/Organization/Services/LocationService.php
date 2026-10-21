<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Core\Support\Search;
use App\Modules\Organization\Models\Location;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class LocationService
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  array{0: string, 1: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, Location>
     */
    public function paginate(array $filters, ?string $search, array $sort, int $perPage): LengthAwarePaginator
    {
        $query = Location::query();

        if (isset($filters['is_active'])) {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        Search::apply($query, ['name', 'code'], $search);

        [$column, $direction] = $sort;

        return $query->orderBy($column, $direction)->orderBy('id')->paginate($perPage)->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Location
    {
        return Location::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Location $location, array $attributes): Location
    {
        $location->update($attributes);

        return $location;
    }

    /**
     * Locations that appear in any assignment (past or present) are history and
     * cannot be deleted; deactivate them instead.
     */
    public function delete(Location $location): void
    {
        if ($location->assignments()->exists()) {
            throw new BusinessRuleException('Employees have been assigned to this location. Deactivate it instead.', 'location_in_use', 409);
        }

        $location->delete();
    }
}
