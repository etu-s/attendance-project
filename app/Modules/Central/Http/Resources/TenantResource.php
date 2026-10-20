<?php

declare(strict_types=1);

namespace App\Modules\Central\Http\Resources;

use App\Modules\Central\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Super-admin view of a tenant. Never exposes the internal `data` column
 * (database name and other tenancy bookkeeping).
 *
 * @mixin Tenant
 */
class TenantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'subdomain' => $this->whenLoaded('domains', fn () => $this->subdomain()),
            'status' => $this->status->value,
            'plan' => $this->whenLoaded('plan', fn () => [
                'id' => $this->plan->id,
                'name' => $this->plan->name,
            ]),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
