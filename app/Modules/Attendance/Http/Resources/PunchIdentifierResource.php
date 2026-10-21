<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Resources;

use App\Modules\Attendance\Models\EmployeePunchIdentifier;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmployeePunchIdentifier
 */
class PunchIdentifierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'identifier' => $this->identifier,
            'label' => $this->label,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
