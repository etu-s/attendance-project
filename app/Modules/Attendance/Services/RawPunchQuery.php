<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Models\RawPunch;
use App\Modules\Core\Support\Search;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The raw punch log, exactly as received (read-only by design).
 */
final class RawPunchQuery
{
    /**
     * @param  array<string, mixed>  $filters
     * @param  array{0: string, 1: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, RawPunch>
     */
    public function paginate(array $filters, ?string $search, array $sort, int $perPage): LengthAwarePaginator
    {
        $query = RawPunch::query()->with(['source', 'match.employee', 'orphan']);

        if (isset($filters['punch_source_id'])) {
            $query->where('punch_source_id', (int) $filters['punch_source_id']);
        }
        if (isset($filters['from'])) {
            $query->where('punched_at', '>=', $filters['from'].' 00:00:00');
        }
        if (isset($filters['to'])) {
            $query->where('punched_at', '<=', $filters['to'].' 23:59:59');
        }
        if (isset($filters['employee_id'])) {
            $query->whereHas('match', fn (Builder $m) => $m->where('employee_id', (int) $filters['employee_id']));
        }

        match ($filters['state'] ?? null) {
            'matched' => $query->whereHas('match'),
            'unmatched' => $query->whereDoesntHave('match'),
            'too_old' => $query->where('too_old', true),
            default => null,
        };

        Search::apply($query, ['employee_identifier'], $search);

        [$column, $direction] = $sort;

        return $query->orderBy($column, $direction)->orderBy('id', $direction)->paginate($perPage)->withQueryString();
    }
}
