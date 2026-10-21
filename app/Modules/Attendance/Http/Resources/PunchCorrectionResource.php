<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Resources;

use App\Modules\Attendance\Enums\CorrectionStatus;
use App\Modules\Attendance\Models\PunchCorrection;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `can_decide` tells the UI whether to offer approve/reject to the viewer;
 * the API enforces the same rules (PunchCorrectionService).
 *
 * @mixin PunchCorrection
 */
class PunchCorrectionResource extends JsonResource
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
            'kind' => $this->kind->value,
            'punched_at' => $this->punched_at?->toIso8601ZuluString(),
            'raw_punch' => $this->rawPunch === null ? null : [
                'id' => $this->rawPunch->id,
                'punched_at' => $this->rawPunch->punched_at->toIso8601ZuluString(),
            ],
            'reason' => $this->reason,
            'status' => $this->status->value,
            'requested_by' => $this->requester === null ? null : ['id' => $this->requester->id, 'name' => $this->requester->name],
            'decided_by' => $this->decider === null ? null : ['id' => $this->decider->id, 'name' => $this->decider->name],
            'decided_at' => $this->decided_at?->toIso8601ZuluString(),
            'decision_note' => $this->decision_note,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'can_decide' => $viewer !== null
                && $this->status === CorrectionStatus::Pending
                && $viewer->can('corrections.approve')
                && $this->requested_by !== $viewer->id
                && $this->employee->user_id !== $viewer->id,
        ];
    }
}
