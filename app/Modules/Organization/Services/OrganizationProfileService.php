<?php

declare(strict_types=1);

namespace App\Modules\Organization\Services;

use App\Modules\Organization\Models\OrganizationProfile;

final class OrganizationProfileService
{
    /**
     * The tenant's single profile row, created from the tenant name on first use.
     */
    public function get(): OrganizationProfile
    {
        return OrganizationProfile::query()->orderBy('id')->first()
            ?? OrganizationProfile::query()->create(['legal_name' => (string) (tenant('name') ?? 'My organization')]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(array $attributes): OrganizationProfile
    {
        $profile = $this->get();
        $profile->update($attributes);

        return $profile;
    }
}
