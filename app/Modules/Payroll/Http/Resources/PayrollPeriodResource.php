<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Resources;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Identity\Models\User;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PayrollPeriod
 */
class PayrollPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = fn (?User $u) => $u === null ? null : ['id' => $u->id, 'name' => $u->name];

        return [
            'id' => $this->id,
            'name' => $this->name,
            'start_date' => $this->start_date->format(LocalDate::FORMAT),
            'end_date' => $this->end_date->format(LocalDate::FORMAT),
            'status' => $this->status->value,
            'locked_by' => $this->whenLoaded('locker', fn () => $user($this->locker)),
            'locked_at' => $this->locked_at?->toIso8601ZuluString(),
            'unlocked_by' => $this->whenLoaded('unlocker', fn () => $user($this->unlocker)),
            'unlocked_at' => $this->unlocked_at?->toIso8601ZuluString(),
            'unlock_reason' => $this->unlock_reason,
        ];
    }
}
