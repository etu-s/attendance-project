<?php

declare(strict_types=1);

namespace App\Modules\Leave\Http\Resources;

use App\Modules\Identity\Models\User;
use App\Modules\Leave\Models\LeaveRequestAttachment;
use App\Modules\Leave\Services\LeaveRules;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LeaveRequestAttachment
 */
class LeaveAttachmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $viewer */
        $viewer = $request->user();

        return [
            'id' => $this->id,
            'name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size_bytes' => $this->size_bytes,
            'uploaded_by' => $this->uploader === null ? null : ['id' => $this->uploader->id, 'name' => $this->uploader->name],
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'can_remove' => $viewer !== null && $this->relationLoaded('request')
                && app(LeaveRules::class)->canRemoveAttachment($viewer, $this->request, $this->resource),
        ];
    }
}
