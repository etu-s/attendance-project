<?php

declare(strict_types=1);

namespace App\Modules\Central\Http\Controllers;

use App\Modules\Central\Http\Requests\DeleteTenantRequest;
use App\Modules\Central\Http\Requests\IndexTenantsRequest;
use App\Modules\Central\Http\Requests\StoreTenantRequest;
use App\Modules\Central\Http\Requests\UpdateTenantRequest;
use App\Modules\Central\Http\Resources\TenantResource;
use App\Modules\Central\Models\Tenant;
use App\Modules\Central\Services\TenantProvisioner;
use App\Modules\Central\Services\TenantService;
use App\Modules\Core\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class TenantController extends Controller
{
    public function __construct(
        private readonly TenantService $tenants,
    ) {}

    public function index(IndexTenantsRequest $request): AnonymousResourceCollection
    {
        return TenantResource::collection($this->tenants->paginate(
            $request->validated(),
            $request->search(),
            $request->sort(),
            $request->perPage(),
        ));
    }

    public function store(StoreTenantRequest $request, TenantProvisioner $provisioner): JsonResponse
    {
        return (new TenantResource($provisioner->provision($request->toData())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Tenant $tenant): TenantResource
    {
        $this->authorize('view', $tenant);

        return new TenantResource($tenant->load(['plan', 'domains']));
    }

    public function update(UpdateTenantRequest $request, Tenant $tenant): TenantResource
    {
        return new TenantResource($this->tenants->update($tenant, $request->validated()));
    }

    public function suspend(Tenant $tenant): TenantResource
    {
        $this->authorize('suspend', $tenant);

        return new TenantResource($this->tenants->suspend($tenant));
    }

    public function activate(Tenant $tenant): TenantResource
    {
        $this->authorize('activate', $tenant);

        return new TenantResource($this->tenants->activate($tenant));
    }

    public function destroy(DeleteTenantRequest $request, Tenant $tenant): Response
    {
        $this->tenants->delete($tenant);

        return response()->noContent();
    }
}
