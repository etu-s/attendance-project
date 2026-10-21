<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Models\Employee;
use App\Modules\Scheduling\Http\Requests\IndexTemporarySchedulesRequest;
use App\Modules\Scheduling\Http\Requests\TemporaryScheduleRequest;
use App\Modules\Scheduling\Http\Resources\TemporaryScheduleResource;
use App\Modules\Scheduling\Models\TemporarySchedule;
use App\Modules\Scheduling\Services\TemporaryScheduleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Every action also requires that the caller may see the employee
 * (Organization's EmployeePolicy, i.e. EmployeeVisibility).
 */
final class TemporaryScheduleController extends Controller
{
    public function __construct(
        private readonly TemporaryScheduleService $temporaries,
    ) {}

    /**
     * Across employees; scoped to the caller's visible employees by the service.
     */
    public function all(IndexTemporarySchedulesRequest $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();

        return TemporaryScheduleResource::collection(
            $this->temporaries->paginate($request->validated(), $request->search(), $request->sort(), $request->perPage(), $user),
        );
    }

    public function index(Employee $employee): AnonymousResourceCollection
    {
        $this->authorize('schedules.view');
        $this->authorize('view', $employee);

        return TemporaryScheduleResource::collection($this->temporaries->forEmployee($employee));
    }

    public function store(TemporaryScheduleRequest $request, Employee $employee): JsonResponse
    {
        $this->authorize('view', $employee);

        return (new TemporaryScheduleResource($this->temporaries->create($employee, $request->validated())))
            ->response()
            ->setStatusCode(201);
    }

    public function update(TemporaryScheduleRequest $request, TemporarySchedule $temporarySchedule): TemporaryScheduleResource
    {
        $this->authorize('view', Employee::withTrashed()->findOrFail($temporarySchedule->employee_id));

        return new TemporaryScheduleResource($this->temporaries->update($temporarySchedule, $request->validated()));
    }

    public function destroy(TemporarySchedule $temporarySchedule): Response
    {
        $this->authorize('schedules.manage');
        $this->authorize('view', Employee::withTrashed()->findOrFail($temporarySchedule->employee_id));
        $this->temporaries->delete($temporarySchedule);

        return response()->noContent();
    }
}
