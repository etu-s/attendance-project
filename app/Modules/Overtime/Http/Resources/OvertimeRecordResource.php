<?php

declare(strict_types=1);

namespace App\Modules\Overtime\Http\Resources;

use App\Modules\Identity\Models\User;
use App\Modules\Overtime\Models\OvertimeRecord;
use App\Modules\Overtime\Services\OvertimeAccess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Minutes are whole minutes; rates whole percentages. `can_decide` tells the
 * UI what to offer (OvertimeAccess enforces the same).
 *
 * @mixin OvertimeRecord
 */
class OvertimeRecordResource extends JsonResource
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
            'employee' => [
                'id' => $this->employee->id,
                'full_name' => $this->employee->fullName(),
                'employee_code' => $this->employee->employee_code,
            ],
            'work_date' => $this->work_date->format('Y-m-d'),
            'source' => $this->source->value,
            'kind' => $this->mainKind()->value,
            'before_minutes' => $this->before_minutes,
            'after_minutes' => $this->after_minutes,
            'rest_day_minutes' => $this->rest_day_minutes,
            'holiday_minutes' => $this->holiday_minutes,
            'calculated_minutes' => $this->calculated_minutes,
            'rates' => ['workday' => $this->rate_workday, 'rest_day' => $this->rate_rest_day, 'holiday' => $this->rate_holiday],
            'weighted_minutes' => $this->weighted_minutes,
            'status' => $this->status->value,
            'approved_minutes' => $this->approved_minutes,
            'approved_weighted_minutes' => $this->approved_weighted_minutes,
            'decided_by' => $this->decider === null ? null : ['id' => $this->decider->id, 'name' => $this->decider->name],
            'decided_at' => $this->decided_at?->toIso8601ZuluString(),
            'decision_note' => $this->decision_note,
            'changed_after_decision' => $this->changed_after_decision,
            'reason' => $this->reason,
            'created_by' => $this->creator === null ? null : ['id' => $this->creator->id, 'name' => $this->creator->name],
            'can_decide' => $viewer !== null && app(OvertimeAccess::class)->canDecide($viewer, $this->resource),
        ];
    }
}
