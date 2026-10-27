<?php

declare(strict_types=1);

namespace App\Modules\Leave\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Leave\Http\Requests\LeaveTypeRequest;
use App\Modules\Leave\Http\Resources\LeaveTypeResource;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveTypeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class LeaveTypeController extends Controller
{
    public function __construct(
        private readonly LeaveTypeService $types,
    ) {}

    /**
     * Everyone who can request or see leave needs the active types; leave.manage
     * also sees inactive ones (?all=1).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        abort_unless($user !== null && ($user->can('leave.request') || $user->can('leave.view') || $user->can('leave.manage')), 403);

        $all = $request->boolean('all') && $user->can('leave.manage');

        return LeaveTypeResource::collection(
            LeaveType::query()->when(! $all, fn ($q) => $q->where('is_active', true))->orderBy('name')->get(),
        );
    }

    public function store(LeaveTypeRequest $request): JsonResponse
    {
        return (new LeaveTypeResource($this->types->create($request->toAttributes())))->response()->setStatusCode(201);
    }

    public function update(LeaveTypeRequest $request, LeaveType $leaveType): LeaveTypeResource
    {
        return new LeaveTypeResource($this->types->update($leaveType, $request->toAttributes()));
    }

    public function destroy(LeaveType $leaveType): Response
    {
        $this->authorize('leave.manage');
        $this->types->delete($leaveType);

        return response()->noContent();
    }
}
