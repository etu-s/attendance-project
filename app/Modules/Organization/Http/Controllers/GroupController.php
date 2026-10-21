<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Organization\Http\Requests\GroupRequest;
use App\Modules\Organization\Http\Requests\IndexGroupsRequest;
use App\Modules\Organization\Http\Requests\SetManagersRequest;
use App\Modules\Organization\Http\Resources\GroupResource;
use App\Modules\Organization\Models\Group;
use App\Modules\Organization\Services\GroupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class GroupController extends Controller
{
    public function __construct(
        private readonly GroupService $groups,
    ) {}

    public function index(IndexGroupsRequest $request): AnonymousResourceCollection
    {
        return GroupResource::collection($this->groups->paginate(
            $request->validated(),
            $request->search(),
            $request->sort(),
            $request->perPage(),
        ));
    }

    public function store(GroupRequest $request): JsonResponse
    {
        $group = $this->groups->create($request->validated());
        $this->groups->attachManagers(collect([$group]));

        return (new GroupResource($group))->response()->setStatusCode(201);
    }

    public function show(Group $group): GroupResource
    {
        $this->authorize('groups.view');
        $this->groups->attachManagers(collect([$group]));

        return new GroupResource($group);
    }

    public function update(GroupRequest $request, Group $group): GroupResource
    {
        $group = $this->groups->update($group, $request->validated());
        $this->groups->attachManagers(collect([$group]));

        return new GroupResource($group);
    }

    public function destroy(Group $group): Response
    {
        $this->authorize('groups.manage');
        $this->groups->delete($group);

        return response()->noContent();
    }

    public function managers(SetManagersRequest $request, Group $group): GroupResource
    {
        return new GroupResource($this->groups->setManagers($group, $request->userIds()));
    }
}
