<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Resources;

use App\Modules\Organization\Models\OrganizationProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrganizationProfile
 */
class OrganizationProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'legal_name' => $this->legal_name,
            'display_name' => $this->display_name,
            'registration_number' => $this->registration_number,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
