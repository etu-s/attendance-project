<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Resources;

use App\Modules\Attendance\Models\OrphanPunch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin OrphanPunch
 */
class OrphanPunchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'raw_punch_id' => $this->raw_punch_id,
            'employee_identifier' => $this->employee_identifier,
            'punched_at' => $this->rawPunch->punched_at->toIso8601ZuluString(),
            'too_old' => $this->rawPunch->too_old,
            'source' => ['id' => $this->rawPunch->source->id, 'name' => $this->rawPunch->source->name],
            'status' => $this->status->value,
            'employee' => $this->employee === null ? null : [
                'id' => $this->employee->id,
                'full_name' => $this->employee->fullName(),
                'employee_code' => $this->employee->employee_code,
            ],
            'decided_at' => $this->decided_at?->toIso8601ZuluString(),
        ];
    }
}
