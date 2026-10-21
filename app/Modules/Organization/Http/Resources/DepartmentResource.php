<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Resources;

use App\Modules\Organization\Models\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Department
 */
class DepartmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'name' => $this->name,
            'code' => $this->code,
            'is_active' => $this->is_active,
            // Present in the tree listing (DepartmentTree::ordered()).
            'depth' => $this->when($this->resource->getAttribute('depth') !== null, fn () => (int) $this->resource->getAttribute('depth')),
            'path' => $this->when($this->resource->getAttribute('path') !== null, fn () => (string) $this->resource->getAttribute('path')),
            'employees_count' => $this->whenCounted('employees'),
            'managers' => $this->whenLoaded('managerSummaries'),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
