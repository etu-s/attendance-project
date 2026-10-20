<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Resources;

use App\Modules\Core\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AuditLog
 */
class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action->value,
            'actor' => [
                'type' => $this->actor_type->value,
                'id' => $this->actor_id,
                'name' => $this->actor_name,
            ],
            'subject' => [
                'type' => $this->auditable_type,
                'id' => $this->auditable_id,
            ],
            'before' => $this->before,
            'after' => $this->after,
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
