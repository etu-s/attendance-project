<?php

declare(strict_types=1);

namespace App\Modules\Leave\Http\Resources;

use App\Modules\Leave\Models\LeaveType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Amounts in days (half-day steps); stored as half-days.
 *
 * @mixin LeaveType
 */
class LeaveTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'color' => $this->color,
            'is_paid' => $this->is_paid,
            'accrual' => $this->accrual->value,
            'entitlement_days' => $this->entitlement_halves / 2,
            'carry_forward_max_days' => $this->carry_forward_max_halves === null ? null : $this->carry_forward_max_halves / 2,
            'negative_limit_days' => $this->negative_limit_halves / 2,
            'unlimited' => $this->unlimited,
            'is_active' => $this->is_active,
        ];
    }

    /**
     * The short form embedded in requests, balances and ledger entries.
     *
     * @return array{id: int, name: string, code: string, color: string|null, is_paid: bool, unlimited: bool}
     */
    public static function brief(LeaveType $type): array
    {
        return [
            'id' => $type->id,
            'name' => $type->name,
            'code' => $type->code,
            'color' => $type->color,
            'is_paid' => $type->is_paid,
            'unlimited' => $type->unlimited,
        ];
    }
}
