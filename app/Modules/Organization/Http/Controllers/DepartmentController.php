<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Organization\Http\Requests\DepartmentRequest;
use App\Modules\Organization\Http\Requests\SetManagersRequest;
use App\Modules\Organization\Http\Resources\DepartmentResource;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Services\DepartmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class DepartmentController extends Controller
{
    public function __construct(
        private readonly DepartmentService $departments,
    ) {}

    /**
     * The whole tree in display order (not paginated; see DepartmentService::tree()).
     * ?is_active=1|0 filters.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('departments.view');

        $active = $request->has('is_active') ? $request->boolean('is_active') : null;

        return DepartmentResource::collection($this->departments->tree($active));
    }

    public function store(DepartmentRequest $request): JsonResponse
    {
        $department = $this->departments->create($request->validated());
        $this->departments->attachManagers(collect([$department]));

        return (new DepartmentResource($department))->response()->setStatusCode(201);
    }

    public function show(Department $department): DepartmentResource
    {
        $this->authorize('departments.view');
        $this->departments->attachManagers(collect([$department]));

        return new DepartmentResource($department);
    }

    public function update(DepartmentRequest $request, Department $department): DepartmentResource
    {
        $department = $this->departments->update($department, $request->validated());
        $this->departments->attachManagers(collect([$department]));

        return new DepartmentResource($department);
    }

    public function destroy(Department $department): Response
    {
        $this->authorize('departments.manage');
        $this->departments->delete($department);

        return response()->noContent();
    }

    public function managers(SetManagersRequest $request, Department $department): DepartmentResource
    {
        return new DepartmentResource($this->departments->setManagers($department, $request->userIds()));
    }
}
