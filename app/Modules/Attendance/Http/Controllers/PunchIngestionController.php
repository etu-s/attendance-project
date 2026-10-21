<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Controllers;

use App\Modules\Attendance\Http\Middleware\AuthenticatePunchSource;
use App\Modules\Attendance\Http\Requests\StorePunchBatchRequest;
use App\Modules\Attendance\Http\Requests\StorePunchRequest;
use App\Modules\Attendance\Models\PunchSource;
use App\Modules\Attendance\Services\PunchIngestor;
use App\Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Device / integration endpoints. The punch is stored and 202 returned;
 * matching and attendance happen on the queue.
 */
final class PunchIngestionController extends Controller
{
    public function __construct(
        private readonly PunchIngestor $ingestor,
    ) {}

    public function store(StorePunchRequest $request): JsonResponse
    {
        /** @var array{employee_identifier: string, punched_at: string, direction?: string|null, idempotency_key?: string|null, meta?: array<string, mixed>|null} $punch */
        $punch = $request->validated();
        [$result] = $this->ingestor->ingest($this->source($request), [$punch]);

        return response()->json(['data' => $result], 202);
    }

    /**
     * Valid items are stored, invalid ones reported; each result keeps the
     * item's index in the request.
     */
    public function batch(StorePunchBatchRequest $request): JsonResponse
    {
        $items = $request->items();
        $valid = array_values(array_filter($items, fn (array $item) => $item['data'] !== null));

        /** @var list<array{employee_identifier: string, punched_at: string, direction?: string|null, idempotency_key?: string|null, meta?: array<string, mixed>|null}> $punches */
        $punches = array_column($valid, 'data');
        $stored = $punches === [] ? [] : $this->ingestor->ingest($this->source($request), $punches);
        $byIndex = array_combine(array_column($valid, 'index'), $stored === [] ? [] : $stored) ?: [];

        $results = array_map(fn (array $item) => isset($byIndex[$item['index']])
            ? ['index' => $item['index'], 'status' => $byIndex[$item['index']]['status'], 'id' => $byIndex[$item['index']]['id'], 'errors' => null]
            : ['index' => $item['index'], 'status' => 'invalid', 'id' => null, 'errors' => $item['errors']], $items);

        $statuses = array_count_values(array_column($results, 'status'));

        return response()->json([
            'data' => $results,
            'meta' => [
                'accepted' => $statuses['accepted'] ?? 0,
                'duplicate' => $statuses['duplicate'] ?? 0,
                'invalid' => $statuses['invalid'] ?? 0,
            ],
        ], 202);
    }

    private function source(Request $request): PunchSource
    {
        /** @var PunchSource $source */
        $source = $request->attributes->get(AuthenticatePunchSource::ATTRIBUTE);

        return $source;
    }
}
