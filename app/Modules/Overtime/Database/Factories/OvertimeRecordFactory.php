<?php

declare(strict_types=1);

namespace App\Modules\Overtime\Database\Factories;

use App\Modules\Overtime\Enums\OvertimeSource;
use App\Modules\Overtime\Enums\OvertimeStatus;
use App\Modules\Overtime\Models\OvertimeRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant context only; set employee_id. Default: one pending calculated hour
 * after the shift on 2026-03-02 at 150%.
 *
 * @extends Factory<OvertimeRecord>
 */
class OvertimeRecordFactory extends Factory
{
    protected $model = OvertimeRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_date' => '2026-03-02',
            'source' => OvertimeSource::Calculated,
            'before_minutes' => 0,
            'after_minutes' => 60,
            'rest_day_minutes' => 0,
            'holiday_minutes' => 0,
            'calculated_minutes' => 60,
            'rate_workday' => 150,
            'rate_rest_day' => 200,
            'rate_holiday' => 200,
            'weighted_minutes' => 90,
            'status' => OvertimeStatus::Pending,
        ];
    }
}
