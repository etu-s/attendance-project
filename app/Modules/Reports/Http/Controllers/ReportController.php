<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers;

use App\Modules\Core\Contracts\PeriodLock;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Reports\Http\Requests\ReportRequest;
use App\Modules\Reports\Reports\Report;
use App\Modules\Reports\Services\DashboardService;
use Illuminate\Http\JsonResponse;

final class ReportController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboard,
        private readonly PeriodLock $locks,
    ) {}

    public function dashboard(ReportRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $params = $request->params();

        return response()->json(['data' => [
            ...$this->dashboard->build($params, $user),
            // Not cached: a lock must show at once.
            'locked_periods' => $this->locks->overlapping($params->from, $params->to),
        ]]);
    }

    /**
     * One page of a tabular report (the route's {report}).
     */
    public function show(ReportRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var Report $report */
        $report = $request->report();

        $params = $request->params();
        $response = $report->page($params, $user, $request->perPage(), $request->pageNumber())->toResponse();
        $response['meta']['locked_periods'] = $this->locks->overlapping($params->from, $params->to);

        return response()->json($response);
    }
}
