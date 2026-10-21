<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Organization\Models\Employee;
use App\Modules\Scheduling\Http\Requests\ScheduleRangeRequest;
use App\Modules\Scheduling\Http\Resources\ResolvedDayPresenter;
use App\Modules\Scheduling\Services\ScheduleResolver;
use Illuminate\Http\JsonResponse;

final class EmployeeScheduleController extends Controller
{
    public function __construct(
        private readonly ScheduleResolver $resolver,
    ) {}

    /**
     * Resolved days for one employee (schedules.view + may see the employee).
     */
    public function show(ScheduleRangeRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('schedules.view');
        $this->authorize('view', $employee);

        return $this->respond($employee, $request);
    }

    /**
     * The signed-in user's own schedule; 404 when the login has no employee.
     */
    public function mine(ScheduleRangeRequest $request): JsonResponse
    {
        $employee = Employee::query()->where('user_id', $request->user()?->getAuthIdentifier())->firstOrFail();

        return $this->respond($employee, $request);
    }

    private function respond(Employee $employee, ScheduleRangeRequest $request): JsonResponse
    {
        $days = $this->resolver->forEmployee($employee, $request->localDate('from'), $request->localDate('to'));

        return response()->json(['data' => ResolvedDayPresenter::many($days)]);
    }
}
