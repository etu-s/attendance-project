<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Database\Factories;

use App\Modules\Payroll\Enums\PayrollPeriodStatus;
use App\Modules\Payroll\Models\PayrollPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant context only. Default: February 2026, locked.
 *
 * @extends Factory<PayrollPeriod>
 */
class PayrollPeriodFactory extends Factory
{
    protected $model = PayrollPeriod::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'February 2026',
            'start_date' => '2026-02-01',
            'end_date' => '2026-02-28',
            'status' => PayrollPeriodStatus::Locked,
            'locked_at' => '2026-03-02 09:00:00',
        ];
    }

    public function between(string $start, string $end, ?string $name = null): static
    {
        return $this->state(fn () => ['start_date' => $start, 'end_date' => $end, 'name' => $name ?? "{$start} – {$end}"]);
    }

    public function unlocked(): static
    {
        return $this->state(fn () => ['status' => PayrollPeriodStatus::Unlocked, 'unlocked_at' => '2026-03-03 09:00:00', 'unlock_reason' => 'Late correction']);
    }
}
