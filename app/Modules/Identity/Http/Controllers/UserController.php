<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Identity\Http\Requests\IndexUsersRequest;
use App\Modules\Identity\Http\Requests\StoreUserRequest;
use App\Modules\Identity\Http\Requests\UpdateUserRequest;
use App\Modules\Identity\Http\Resources\UserResource;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class UserController extends Controller
{
    public function __construct(
        private readonly UserService $users,
    ) {}

    public function index(IndexUsersRequest $request): AnonymousResourceCollection
    {
        return UserResource::collection($this->users->paginate(
            $request->validated(),
            $request->search(),
            $request->sort(),
            $request->perPage(),
        ));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        return (new UserResource($this->users->create($request->validated(), $actor)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * (Re)sends an invitation to set a password; the link replaces earlier ones.
     */
    public function invite(User $user): JsonResponse
    {
        $this->authorize('update', $user);
        $this->users->invite($user);

        return response()->json(['message' => "Invitation sent to {$user->email}."], 202);
    }

    public function show(User $user): UserResource
    {
        $this->authorize('view', $user);

        return new UserResource($user->load('roles'));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return new UserResource($this->users->update($user, $request->validated(), $actor));
    }

    public function destroy(Request $request, User $user): Response
    {
        $this->authorize('delete', $user);

        /** @var User $actor */
        $actor = $request->user();
        $this->users->delete($user, $actor);

        return response()->noContent();
    }
}
