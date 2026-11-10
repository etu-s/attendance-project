<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Controllers;

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
    ) {}

    public function dashboard(ReportRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->dashboard->build($request->params(), $user)]);
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

        return response()->json(
            $report->page($request->params(), $user, $request->perPage(), $request->pageNumber())->toResponse(),
        );
    }
}
