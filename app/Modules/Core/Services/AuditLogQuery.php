<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class AuditLogQuery
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  validated IndexAuditLogsRequest input
     * @param  array{0: string, 1: 'asc'|'desc'}  $sort
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginate(array $filters, array $sort, int $perPage): LengthAwarePaginator
    {
        $query = AuditLog::query();

        foreach (['action', 'actor_type', 'actor_id'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        if (isset($filters['subject_type'])) {
            $query->where('auditable_type', $filters['subject_type']);
        }

        if (isset($filters['subject_id'])) {
            $query->where('auditable_id', $filters['subject_id']);
        }

        // `from`/`to` are calendar days as the tenant sees them; created_at is UTC.
        $timezone = (string) $this->settings->get('general.timezone');

        if (isset($filters['from'])) {
            $from = CarbonImmutable::createFromFormat('Y-m-d', (string) $filters['from'], $timezone)->startOfDay()->utc();
            $query->where('created_at', '>=', $from->format('Y-m-d H:i:s'));
        }

        if (isset($filters['to'])) {
            $to = CarbonImmutable::createFromFormat('Y-m-d', (string) $filters['to'], $timezone)->addDay()->startOfDay()->utc();
            $query->where('created_at', '<', $to->format('Y-m-d H:i:s'));
        }

        [$column, $direction] = $sort;

        return $query
            ->orderBy($column, $direction)
            ->orderBy('id', $direction)
            ->paginate($perPage)
            ->withQueryString();
    }
}
