<?php

declare(strict_types=1);

namespace App\Modules\Central\Http\Controllers;

use App\Modules\Central\Http\Requests\IndexPlansRequest;
use App\Modules\Central\Http\Requests\StorePlanRequest;
use App\Modules\Central\Http\Requests\UpdatePlanRequest;
use App\Modules\Central\Http\Resources\PlanResource;
use App\Modules\Central\Models\Plan;
use App\Modules\Central\Services\PlanService;
use App\Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class PlanController extends Controller
{
    public function __construct(
        private readonly PlanService $plans,
    ) {}

    public function index(IndexPlansRequest $request): AnonymousResourceCollection
    {
        return PlanResource::collection($this->plans->paginate(
            $request->validated(),
            $request->search(),
            $request->sort(),
            $request->perPage(),
        ));
    }

    public function store(StorePlanRequest $request): JsonResponse
    {
        return (new PlanResource($this->plans->create($request->validated())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Plan $plan): PlanResource
    {
        $this->authorize('view', $plan);

        return new PlanResource($plan->loadCount('tenants'));
    }

    public function update(UpdatePlanRequest $request, Plan $plan): PlanResource
    {
        return new PlanResource($this->plans->update($plan, $request->validated()));
    }

    public function destroy(Plan $plan): Response
    {
        $this->authorize('delete', $plan);

        $this->plans->delete($plan);

        return response()->noContent();
    }
}
