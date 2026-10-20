<?php

declare(strict_types=1);

namespace App\Modules\Central\Http\Resources;

use App\Modules\Central\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Plan
 */
class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'max_employees' => $this->max_employees,
            'features' => $this->features ?? [],
            'is_active' => $this->is_active,
            'tenants_count' => $this->whenCounted('tenants'),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
