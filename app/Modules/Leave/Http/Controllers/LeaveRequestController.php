<?php

declare(strict_types=1);

namespace App\Modules\Leave\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Http\Requests\DecideLeaveRequest;
use App\Modules\Leave\Http\Requests\IndexLeaveRequestsRequest;
use App\Modules\Leave\Http\Requests\LeaveRequestPayload;
use App\Modules\Leave\Http\Resources\LeavePresenter;
use App\Modules\Leave\Http\Resources\LeaveRequestResource;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveQuery;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Modules\Organization\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * Every action also requires that the caller may see the employee
 * (EmployeePolicy, i.e. EmployeeVisibility); employees see themselves.
 */
final class LeaveRequestController extends Controller
{
    public function __construct(
        private readonly LeaveRequestService $requests,
        private readonly LeaveQuery $query,
    ) {}

    public function index(IndexLeaveRequestsRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return LeaveRequestResource::collection(
            $this->query->paginate($request->validated(), $request->search(), $request->sort(), $request->perPage(), $user),
        );
    }

    /**
     * Counted days and the balance afterwards, before submitting.
     */
    public function preview(LeaveRequestPayload $request): JsonResponse
    {
        $employee = $this->employee($request);
        $type = LeaveType::query()->find((int) $request->validated('leave_type_id'));
        if ($type === null) {
            throw ValidationException::withMessages(['leave_type_id' => 'Choose a leave type.']);
        }

        $preview = $this->requests->preview(
            $employee,
            $type,
            $request->localDate('start_date'),
            $request->localDate('end_date'),
            $request->boolean('half_start'),
            $request->boolean('half_end'),
        );

        return response()->json(['data' => LeavePresenter::preview($preview)]);
    }

    public function store(LeaveRequestPayload $request): JsonResponse
    {
        $employee = $this->employee($request);

        /** @var User $user */
        $user = $request->user();
        /** @var array{leave_type_id: int, start_date: string, end_date: string, half_start?: bool, half_end?: bool, reason?: string|null} $data */
        $data = $request->validated();

        return (new LeaveRequestResource($this->requests->create($employee, $data, $user)))->response()->setStatusCode(201);
    }

    public function show(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $user = $request->user();
        abort_unless($user !== null && ($user->can('leave.request') || $user->can('leave.view')), 403);
        $this->authorize('view', $leaveRequest->employee);

        return new LeaveRequestResource($leaveRequest->load(['employee', 'type', 'requester', 'decider', 'days', 'attachments.uploader']));
    }

    public function approve(DecideLeaveRequest $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $this->authorize('view', $leaveRequest->employee);

        /** @var User $user */
        $user = $request->user();

        return new LeaveRequestResource($this->requests->approve($leaveRequest, $user, $request->validated('note')));
    }

    public function reject(DecideLeaveRequest $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $this->authorize('view', $leaveRequest->employee);

        /** @var User $user */
        $user = $request->user();

        return new LeaveRequestResource($this->requests->reject($leaveRequest, $user, $request->validated('note')));
    }

    public function cancel(Request $request, LeaveRequest $leaveRequest): LeaveRequestResource
    {
        $this->authorize('view', $leaveRequest->employee);

        /** @var User $user */
        $user = $request->user();

        return new LeaveRequestResource($this->requests->cancel($leaveRequest, $user));
    }

    private function employee(LeaveRequestPayload $request): Employee
    {
        $employee = Employee::query()->findOrFail((int) $request->validated('employee_id'));
        $this->authorize('view', $employee);

        return $employee;
    }
}
