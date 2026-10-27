<?php

declare(strict_types=1);

namespace App\Modules\Leave\Services;

use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Leave\Models\LeaveType;
use Illuminate\Support\Facades\DB;

final class LeaveTypeService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): LeaveType
    {
        return LeaveType::query()->create($this->normalise($data));
    }

    /**
     * Changes apply from now on: entries already in the ledger stay as written.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(LeaveType $type, array $data): LeaveType
    {
        $type->update($this->normalise($data + $type->only(['unlimited'])));

        return $type;
    }

    public function delete(LeaveType $type): void
    {
        $used = DB::table('leave_requests')->where('leave_type_id', $type->id)->exists()
            || DB::table('leave_ledger')->where('leave_type_id', $type->id)->exists();

        if ($used) {
            throw new BusinessRuleException('This leave type has requests or balances. Deactivate it instead.', 'leave_type_in_use', 409);
        }

        $type->delete();
    }

    /**
     * An unlimited type keeps no balance, so nothing accrues or carries over.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        if ((bool) ($data['unlimited'] ?? false)) {
            $data['accrual'] = 'none';
            $data['entitlement_halves'] = 0;
            $data['carry_forward_max_halves'] = null;
            $data['negative_limit_halves'] = 0;
        }

        return $data;
    }
}
