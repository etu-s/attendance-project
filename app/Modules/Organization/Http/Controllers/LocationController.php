<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Organization\Http\Requests\IndexLocationsRequest;
use App\Modules\Organization\Http\Requests\LocationRequest;
use App\Modules\Organization\Http\Resources\LocationResource;
use App\Modules\Organization\Models\Location;
use App\Modules\Organization\Services\LocationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class LocationController extends Controller
{
    public function __construct(
        private readonly LocationService $locations,
    ) {}

    public function index(IndexLocationsRequest $request): AnonymousResourceCollection
    {
        return LocationResource::collection($this->locations->paginate(
            $request->validated(),
            $request->search(),
            $request->sort(),
            $request->perPage(),
        ));
    }

    public function store(LocationRequest $request): JsonResponse
    {
        return (new LocationResource($this->locations->create($request->validated())))->response()->setStatusCode(201);
    }

    public function show(Location $location): LocationResource
    {
        $this->authorize('locations.view');

        return new LocationResource($location);
    }

    public function update(LocationRequest $request, Location $location): LocationResource
    {
        return new LocationResource($this->locations->update($location, $request->validated()));
    }

    public function destroy(Location $location): Response
    {
        $this->authorize('locations.manage');
        $this->locations->delete($location);

        return response()->noContent();
    }
}
