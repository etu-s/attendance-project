<?php

declare(strict_types=1);

namespace App\Modules\Overtime\Http\Controllers;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Employee;
use App\Modules\Overtime\Http\Requests\ApproveOvertimeBatchRequest;
use App\Modules\Overtime\Http\Requests\DecideOvertimeRequest;
use App\Modules\Overtime\Http\Requests\IndexOvertimeRequest;
use App\Modules\Overtime\Http\Requests\OvertimeRangeRequest;
use App\Modules\Overtime\Http\Resources\OvertimeRecordResource;
use App\Modules\Overtime\Jobs\RecalculateOvertime;
use App\Modules\Overtime\Models\OvertimeRecord;
use App\Modules\Overtime\Services\OvertimeQuery;
use App\Modules\Overtime\Services\OvertimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class OvertimeController extends Controller
{
    public function __construct(
        private readonly OvertimeQuery $query,
        private readonly OvertimeService $overtime,
    ) {}

    public function index(IndexOvertimeRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return OvertimeRecordResource::collection(
            $this->query->paginate($request->validated(), $request->search(), $request->sort(), $request->perPage(), $user),
        );
    }

    /**
     * Per-employee totals over a range (for payroll handover and reports).
     */
    public function summary(IndexOvertimeRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $page = $this->query->summary($request->validated(), $request->search(), $request->perPage(), $user);
        $employees = Employee::withTrashed()->whereKey(array_map(fn ($row) => (int) $row->employee_id, $page->items()))->get()->keyBy('id');

        return response()->json([
            'data' => array_map(function ($row) use ($employees): array {
                $employee = $employees->get((int) $row->employee_id);

                return [
                    'employee' => $employee === null ? null : [
                        'id' => $employee->id,
                        'full_name' => $employee->fullName(),
                        'employee_code' => $employee->employee_code,
                    ],
                    'approved_minutes' => (int) $row->approved_minutes,
                    'approved_weighted_minutes' => (int) $row->approved_weighted_minutes,
                    'approved_workday_minutes' => (int) $row->approved_workday_minutes,
                    'approved_rest_day_minutes' => (int) $row->approved_rest_day_minutes,
                    'approved_holiday_minutes' => (int) $row->approved_holiday_minutes,
                    'pending_minutes' => (int) $row->pending_minutes,
                    'pending_count' => (int) $row->pending_count,
                ];
            }, $page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }

    public function approve(DecideOvertimeRequest $request, OvertimeRecord $overtimeRecord): OvertimeRecordResource
    {
        $this->authorize('view', $overtimeRecord->employee);

        /** @var User $user */
        $user = $request->user();
        $minutes = $request->validated('minutes');

        return new OvertimeRecordResource($this->overtime->approve($overtimeRecord, $user, $minutes === null ? null : (int) $minutes, $request->validated('note'))
            ->load(['employee', 'decider', 'creator']));
    }

    public function reject(DecideOvertimeRequest $request, OvertimeRecord $overtimeRecord): OvertimeRecordResource
    {
        $this->authorize('view', $overtimeRecord->employee);

        /** @var User $user */
        $user = $request->user();

        return new OvertimeRecordResource($this->overtime->reject($overtimeRecord, $user, $request->validated('note'))
            ->load(['employee', 'decider', 'creator']));
    }

    /**
     * Approves, as calculated, every listed record the caller may decide.
     */
    public function approveBatch(ApproveOvertimeBatchRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var list<int> $ids */
        $ids = array_map('intval', (array) $request->validated('ids'));

        return response()->json(['data' => $this->overtime->approveMany($ids, $user)]);
    }

    public function recalculate(OvertimeRangeRequest $request): JsonResponse
    {
        $this->authorize('overtime.manage');
        $employeeId = $request->validated('employee_id');
        if ($employeeId !== null) {
            $this->authorize('view', Employee::query()->findOrFail((int) $employeeId));
        }

        RecalculateOvertime::dispatch(
            $employeeId === null ? null : [(int) $employeeId],
            $request->localDate('from')->format(LocalDate::FORMAT),
            $request->localDate('to')->format(LocalDate::FORMAT),
        );

        return response()->json(['message' => 'Recalculation queued.'], 202);
    }

    public function employee(OvertimeRangeRequest $request, Employee $employee): AnonymousResourceCollection
    {
        $this->authorize('overtime.view');
        $this->authorize('view', $employee);

        return OvertimeRecordResource::collection($this->query->forEmployee($employee, $request->localDate('from'), $request->localDate('to')));
    }

    /**
     * The signed-in employee's own overtime; 404 when the login has no employee.
     */
    public function mine(OvertimeRangeRequest $request): AnonymousResourceCollection
    {
        $employee = Employee::query()->where('user_id', $request->user()?->getAuthIdentifier())->firstOrFail();

        return OvertimeRecordResource::collection($this->query->forEmployee($employee, $request->localDate('from'), $request->localDate('to')));
    }
}
