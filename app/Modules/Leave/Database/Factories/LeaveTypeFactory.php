<?php

declare(strict_types=1);

namespace App\Modules\Leave\Database\Factories;

use App\Modules\Leave\Enums\LeaveAccrual;
use App\Modules\Leave\Models\LeaveType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant context only. Default: paid annual leave, 14 days granted yearly,
 * up to 5 days carried forward, no negative balance.
 *
 * @extends Factory<LeaveType>
 */
class LeaveTypeFactory extends Factory
{
    protected $model = LeaveType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Annual leave',
            'code' => 'L'.fake()->unique()->numberBetween(100, 9999999),
            'color' => '#2563eb',
            'is_paid' => true,
            'accrual' => LeaveAccrual::Yearly,
            'entitlement_halves' => 28,
            'carry_forward_max_halves' => 10,
            'negative_limit_halves' => 0,
            'unlimited' => false,
            'is_active' => true,
        ];
    }

    public function unlimited(): static
    {
        return $this->state(['name' => 'Unpaid leave', 'is_paid' => false, 'accrual' => LeaveAccrual::None, 'entitlement_halves' => 0, 'carry_forward_max_halves' => null, 'unlimited' => true]);
    }

    public function monthly(int $entitlementHalves = 24): static
    {
        return $this->state(['accrual' => LeaveAccrual::Monthly, 'entitlement_halves' => $entitlementHalves]);
    }
}
