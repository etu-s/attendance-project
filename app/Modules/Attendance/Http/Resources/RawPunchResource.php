<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Resources;

use App\Modules\Attendance\Models\RawPunch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `state`: matched (belongs to an employee), unmatched (open orphan),
 * dismissed, or pending (not processed yet). `too_old` punches are kept but
 * never counted.
 *
 * @mixin RawPunch
 */
class RawPunchResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $employee = $this->match?->employee;

        return [
            'id' => $this->id,
            'source' => ['id' => $this->source->id, 'name' => $this->source->name],
            'employee_identifier' => $this->employee_identifier,
            'punched_at' => $this->punched_at->toIso8601ZuluString(),
            'direction' => $this->direction?->value,
            'idempotency_key' => $this->idempotency_key,
            'meta' => $this->meta,
            'too_old' => $this->too_old,
            'received_at' => $this->received_at->toIso8601ZuluString(),
            'state' => match (true) {
                $this->match !== null => 'matched',
                $this->orphan !== null => $this->orphan->status->value === 'open' ? 'unmatched' : $this->orphan->status->value,
                default => 'pending',
            },
            'employee' => $employee === null ? null : [
                'id' => $employee->id,
                'full_name' => $employee->fullName(),
                'employee_code' => $employee->employee_code,
            ],
        ];
    }
}
