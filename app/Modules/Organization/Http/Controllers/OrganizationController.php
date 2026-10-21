<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Controllers;

use App\Modules\Core\Http\Controllers\Controller;
use App\Modules\Organization\Http\Requests\UpdateOrganizationRequest;
use App\Modules\Organization\Http\Resources\OrganizationProfileResource;
use App\Modules\Organization\Services\OrganizationProfileService;

final class OrganizationController extends Controller
{
    public function __construct(
        private readonly OrganizationProfileService $profile,
    ) {}

    public function show(): OrganizationProfileResource
    {
        $this->authorize('organization.view');

        return new OrganizationProfileResource($this->profile->get());
    }

    public function update(UpdateOrganizationRequest $request): OrganizationProfileResource
    {
        return new OrganizationProfileResource($this->profile->update($request->validated()));
    }
}
