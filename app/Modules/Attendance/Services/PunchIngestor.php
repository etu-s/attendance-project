<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Services;

use App\Modules\Attendance\Jobs\ProcessPunches;
use App\Modules\Attendance\Models\PunchSource;
use App\Modules\Core\Services\SettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stores raw punches and queues their processing; nothing else happens on
 * the request path, so ingestion stays fast. A batch is one transaction.
 *
 * Idempotency is enforced by unique indexes: the same source + identifier +
 * instant, or the same source + idempotency key, is reported as a duplicate
 * and returns the original punch's id.
 */
final class PunchIngestor
{
    private const DATETIME = 'Y-m-d H:i:s';

    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  list<array{employee_identifier: string, punched_at: string, direction?: string|null, idempotency_key?: string|null, meta?: array<string, mixed>|null}>  $punches  validated
     * @return list<array{status: 'accepted'|'duplicate', id: int}>
     */
    public function ingest(PunchSource $source, array $punches): array
    {
        $now = CarbonImmutable::now('UTC');
        // Late arrivals older than the backlog window are kept but never counted.
        $oldest = $now->subDays((int) $this->settings->get('attendance.backlog_days'));

        $results = DB::transaction(function () use ($source, $punches, $now, $oldest): array {
            $results = [];

            foreach ($punches as $punch) {
                $at = CarbonImmutable::parse($punch['punched_at'])->utc()->startOfSecond();
                $row = [
                    'punch_source_id' => $source->id,
                    'employee_identifier' => trim($punch['employee_identifier']),
                    'punched_at' => $at->format(self::DATETIME),
                    'direction' => $punch['direction'] ?? null,
                    'idempotency_key' => $punch['idempotency_key'] ?? null,
                    'meta' => isset($punch['meta']) ? json_encode($punch['meta'], JSON_THROW_ON_ERROR) : null,
                    'too_old' => $at->lessThan($oldest),
                    'received_at' => $now->format(self::DATETIME),
                ];

                if (DB::table('raw_punches')->insertOrIgnore($row) === 1) {
                    $results[] = ['status' => 'accepted', 'id' => (int) DB::getPdo()->lastInsertId()];

                    continue;
                }

                $results[] = ['status' => 'duplicate', 'id' => $this->existingId($row)];
            }

            return $results;
        });

        if (in_array('accepted', array_column($results, 'status'), true)) {
            ProcessPunches::dispatch();
        }

        // Shown on the sources screen; at most one write a minute per source.
        if ($source->last_used_at === null || $source->last_used_at->lessThan($now->subMinute())) {
            $source->forceFill(['last_used_at' => $now])->saveQuietly();
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function existingId(array $row): int
    {
        $query = DB::table('raw_punches')->where('punch_source_id', $row['punch_source_id']);

        $byKey = $row['idempotency_key'] !== null
            ? (clone $query)->where('idempotency_key', $row['idempotency_key'])->value('id')
            : null;

        return (int) ($byKey ?? $query
            ->where('employee_identifier', $row['employee_identifier'])
            ->where('punched_at', $row['punched_at'])
            ->value('id'));
    }
}
