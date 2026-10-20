<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

final class HealthController extends Controller
{
    /**
     * Public liveness check for a tenant: confirms its database is reachable.
     * Deliberately reveals nothing about the tenant or the failure.
     */
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            $database = 'ok';
        } catch (Throwable $e) {
            report($e);
            $database = 'unavailable';
        }

        return response()->json(
            ['status' => $database === 'ok' ? 'ok' : 'degraded', 'database' => $database],
            $database === 'ok' ? 200 : 503,
        );
    }
}
