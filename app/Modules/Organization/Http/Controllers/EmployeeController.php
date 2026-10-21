<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Identity\Models\User;
use App\Modules\Organization\Http\Requests\EmployeeActionRequest;
use App\Modules\Organization\Http\Requests\IndexEmployeesRequest;
use App\Modules\Organization\Http\Requests\StoreEmployeeRequest;
use App\Modules\Organization\Http\Requests\UpdateEmployeeRequest;
use App\Modules\Organization\Http\Resources\EmployeeHistoryResource;
use App\Modules\Organization\Http\Resources\EmployeeResource;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\Group;
use App\Modules\Organization\Services\AssignmentService;
use App\Modules\Organization\Services\EmployeeService;
use App\Modules\Organization\Services\MembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class EmployeeController extends Controller
{
    public function __construct(
        private readonly EmployeeService $employees,
    ) {}

    public function index(IndexEmployeesRequest $request): AnonymousResourceCollection
    {
        return EmployeeResource::collection($this->employees->paginate(
            $request->validated(),
            $request->search(),
            $request->sort(),
            $request->perPage(),
            $this->actor($request),
        ));
    }

    public function store(StoreEmployeeRequest $request): JsonResponse
    {
        return (new EmployeeResource($this->employees->create($request->validated(), $this->actor($request))))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Employee $employee): EmployeeResource
    {
        $this->authorize('view', $employee);

        return new EmployeeResource($this->employees->load($employee));
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): EmployeeResource
    {
        return new EmployeeResource($this->employees->update($employee, $request->validated()));
    }

    public function destroy(Employee $employee): Response
    {
        $this->authorize('delete', $employee);
        $this->employees->delete($employee);

        return response()->noContent();
    }

    public function history(Employee $employee, AssignmentService $assignments, MembershipService $memberships): EmployeeHistoryResource
    {
        $this->authorize('view', $employee);

        return new EmployeeHistoryResource([
            'assignments' => $assignments->history($employee),
            'memberships' => $memberships->history($employee),
        ]);
    }

    public function transfer(EmployeeActionRequest $request, Employee $employee): EmployeeResource
    {
        $location = $request->validated('location_id');

        return new EmployeeResource($this->employees->transfer(
            $employee,
            (int) $request->validated('department_id'),
            $location === null ? null : (int) $location,
            $request->localDate('effective_from'),
        ));
    }

    public function terminate(EmployeeActionRequest $request, Employee $employee): EmployeeResource
    {
        return new EmployeeResource($this->employees->terminate(
            $employee,
            $request->localDate('termination_date'),
            $request->boolean('deactivate_login', true),
            $this->actor($request),
        ));
    }

    public function reinstate(EmployeeActionRequest $request, Employee $employee): EmployeeResource
    {
        $department = $request->validated('department_id');
        $location = $request->validated('location_id');

        return new EmployeeResource($this->employees->reinstate(
            $employee,
            $request->localDate('effective_from'),
            $department === null ? null : (int) $department,
            $location === null ? null : (int) $location,
        ));
    }

    public function joinGroup(EmployeeActionRequest $request, Employee $employee): EmployeeResource
    {
        $group = Group::query()->findOrFail((int) $request->validated('group_id'));

        return new EmployeeResource($this->employees->joinGroup($employee, $group, $request->localDate('effective_from')));
    }

    public function leaveGroup(EmployeeActionRequest $request, Employee $employee, Group $group): EmployeeResource
    {
        return new EmployeeResource($this->employees->leaveGroup($employee, $group, $request->localDate('effective_to')));
    }

    public function linkUser(EmployeeActionRequest $request, Employee $employee): EmployeeResource
    {
        $userId = $request->validated('user_id');

        return new EmployeeResource($this->employees->linkUser($employee, $userId === null ? null : (int) $userId));
    }

    private function actor(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
