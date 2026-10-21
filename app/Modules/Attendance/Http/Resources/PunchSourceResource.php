<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Resources;

use App\Modules\Attendance\Models\PunchSource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Never contains the key or the signing secret; those are returned once by
 * the create and rotate endpoints, next to this resource.
 *
 * @mixin PunchSource
 */
class PunchSourceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'key_hint' => 'ams_'.$this->key_prefix.'_…',
            'signed' => $this->requiresSignature(),
            'ip_allowlist' => $this->ip_allowlist ?? [],
            'is_active' => $this->is_active,
            'last_used_at' => $this->last_used_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
