<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Database\Factories;

use App\Modules\Attendance\Enums\CorrectionKind;
use App\Modules\Attendance\Enums\CorrectionStatus;
use App\Modules\Attendance\Models\PunchCorrection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant context only. Default: a pending "add" of 09:00 UTC on 2026-03-02;
 * set employee_id.
 *
 * @extends Factory<PunchCorrection>
 */
class PunchCorrectionFactory extends Factory
{
    protected $model = PunchCorrection::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_date' => '2026-03-02',
            'kind' => CorrectionKind::Add,
            'punched_at' => '2026-03-02 09:00:00',
            'raw_punch_id' => null,
            'reason' => 'Forgot to punch in',
            'status' => CorrectionStatus::Pending,
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => CorrectionStatus::Approved, 'decided_at' => '2026-03-02 10:00:00']);
    }
}
