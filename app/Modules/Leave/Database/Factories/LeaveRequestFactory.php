<?php

declare(strict_types=1);

namespace App\Modules\Leave\Database\Factories;

use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Models\LeaveRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant context only; set employee_id and leave_type_id. Rows made here have
 * no counted days or ledger entries: create real requests through
 * LeaveRequestService when those matter.
 *
 * @extends Factory<LeaveRequest>
 */
class LeaveRequestFactory extends Factory
{
    protected $model = LeaveRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'start_date' => '2026-03-02',
            'end_date' => '2026-03-02',
            'half_start' => false,
            'half_end' => false,
            'days_halves' => 2,
            'reason' => null,
            'status' => LeaveRequestStatus::Pending,
        ];
    }
}
