<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Controllers;

use App\Modules\Attendance\Http\Requests\AttendanceRangeRequest;
use App\Modules\Attendance\Http\Requests\AttendanceSummaryRequest;
use App\Modules\Attendance\Http\Requests\IndexAttendanceRequest;
use App\Modules\Attendance\Http\Requests\RecalculateAttendanceRequest;
use App\Modules\Attendance\Http\Resources\AttendanceDayResource;
use App\Modules\Attendance\Http\Resources\PunchCorrectionResource;
use App\Modules\Attendance\Jobs\RecalculateAttendance;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\PunchCorrection;
use App\Modules\Attendance\Services\AttendanceQuery;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Services\EmployeeVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceQuery $attendance,
        private readonly EmployeeVisibility $visibility,
    ) {}

    public function index(IndexAttendanceRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return AttendanceDayResource::collection(
            $this->attendance->paginate($request->validated(), $request->search(), $request->sort(), $request->perPage(), $user),
        );
    }

    /**
     * Counts per status on one date (the Today board).
     */
    public function summary(AttendanceSummaryRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $counts = $this->attendance->summary($request->workDate(), $request->validated(), $user);

        return response()->json(['data' => [
            'date' => $request->workDate()->format(LocalDate::FORMAT),
            'counts' => $counts,
            'total' => array_sum($counts),
        ]]);
    }

    /**
     * One day with its periods, punches and corrections. Visible to whoever
     * may see the employee's attendance, and to the employee themself.
     */
    public function show(Request $request, AttendanceDay $attendanceDay): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $employee = $attendanceDay->employee;

        $own = $employee->user_id !== null && $employee->user_id === $user->id;
        if (! $own && ! ($user->can('attendance.view') && $this->visibility->canSee($user, $employee))) {
            abort(403);
        }

        $attendanceDay->load(['employee', 'periods', 'punches.rawPunch.source']);
        $corrections = PunchCorrection::query()
            ->with(['employee', 'requester', 'decider', 'rawPunch'])
            ->where('employee_id', $attendanceDay->employee_id)
            ->where('work_date', $attendanceDay->work_date->format(LocalDate::FORMAT))
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => (new AttendanceDayResource($attendanceDay))->resolve($request),
            'corrections' => PunchCorrectionResource::collection($corrections)->resolve($request),
        ]);
    }

    public function employee(AttendanceRangeRequest $request, Employee $employee): AnonymousResourceCollection
    {
        $this->authorize('attendance.view');
        $this->authorize('view', $employee);

        return AttendanceDayResource::collection($this->attendance->forEmployee($employee, $request->localDate('from'), $request->localDate('to')));
    }

    /**
     * The signed-in employee's own attendance; 404 when the login has no employee.
     */
    public function mine(AttendanceRangeRequest $request): AnonymousResourceCollection
    {
        $employee = Employee::query()->where('user_id', $request->user()?->getAuthIdentifier())->firstOrFail();

        return AttendanceDayResource::collection($this->attendance->forEmployee($employee, $request->localDate('from'), $request->localDate('to')));
    }

    public function recalculate(RecalculateAttendanceRequest $request): JsonResponse
    {
        $employeeId = $request->validated('employee_id');

        if ($employeeId !== null) {
            $this->authorize('view', Employee::query()->findOrFail((int) $employeeId));
        }

        RecalculateAttendance::dispatch(
            $employeeId === null ? null : [(int) $employeeId],
            $request->localDate('from')->format(LocalDate::FORMAT),
            $request->localDate('to')->format(LocalDate::FORMAT),
        );

        return response()->json(['message' => 'Recalculation queued.'], 202);
    }
}
