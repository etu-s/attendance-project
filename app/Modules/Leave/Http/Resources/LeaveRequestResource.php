<?php

declare(strict_types=1);

namespace App\Modules\Leave\Http\Resources;

use App\Modules\Identity\Models\User;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveRequestDay;
use App\Modules\Leave\Services\LeaveRules;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `can_decide` / `can_cancel` tell the UI what to offer; the API enforces the
 * same rules (LeaveRules).
 *
 * @mixin LeaveRequest
 */
class LeaveRequestResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User|null $viewer */
        $viewer = $request->user();
        $rules = app(LeaveRules::class);

        return [
            'id' => $this->id,
            'employee' => [
                'id' => $this->employee->id,
                'full_name' => $this->employee->fullName(),
                'employee_code' => $this->employee->employee_code,
            ],
            'type' => LeaveTypeResource::brief($this->type),
            'start_date' => $this->start_date->format('Y-m-d'),
            'end_date' => $this->end_date->format('Y-m-d'),
            'half_start' => $this->half_start,
            'half_end' => $this->half_end,
            'days' => $this->days_halves / 2,
            'reason' => $this->reason,
            'status' => $this->status->value,
            'requested_by' => $this->requester === null ? null : ['id' => $this->requester->id, 'name' => $this->requester->name],
            'decided_by' => $this->decider === null ? null : ['id' => $this->decider->id, 'name' => $this->decider->name],
            'decided_at' => $this->decided_at?->toIso8601ZuluString(),
            'decision_note' => $this->decision_note,
            'cancelled_at' => $this->cancelled_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'counted_days' => $this->whenLoaded('days', fn () => $this->days->map(fn (LeaveRequestDay $d) => [
                'date' => $d->date->format('Y-m-d'),
                'portion' => $d->portion->value,
            ])->all()),
            'can_decide' => $viewer !== null && $rules->canDecide($viewer, $this->resource),
            'can_cancel' => $viewer !== null && $rules->canCancel($viewer, $this->resource),
        ];
    }
}
