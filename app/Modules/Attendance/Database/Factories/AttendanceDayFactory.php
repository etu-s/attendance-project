<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Database\Factories;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant context only; set employee_id (and department_id / location_id for
 * reports). Default: a present 8-hour office day on 2026-03-02 (UTC).
 * Real attendance comes from AttendanceCalculator; this is for tests of
 * modules that read calculated days.
 *
 * @extends Factory<AttendanceDay>
 */
class AttendanceDayFactory extends Factory
{
    protected $model = AttendanceDay::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'work_date' => '2026-03-02',
            'status' => AttendanceStatus::Present,
            'timezone' => 'UTC',
            'scheduled_minutes' => 480,
            'worked_minutes' => 480,
            'late_minutes' => 0,
            'early_leave_minutes' => 0,
            'unmatched_punches' => 0,
            'fingerprint' => sha1((string) fake()->unique()->numberBetween()),
            'calculated_at' => '2026-03-02 18:00:00',
        ];
    }

    public function late(int $minutes = 20): static
    {
        return $this->state(fn () => ['status' => AttendanceStatus::Late, 'late_minutes' => $minutes, 'worked_minutes' => 480 - $minutes]);
    }

    public function earlyLeave(int $minutes = 30): static
    {
        return $this->state(fn () => ['status' => AttendanceStatus::EarlyLeave, 'early_leave_minutes' => $minutes, 'worked_minutes' => 480 - $minutes]);
    }

    public function absent(): static
    {
        return $this->state(fn () => ['status' => AttendanceStatus::Absent, 'worked_minutes' => 0]);
    }

    /**
     * A day without expected work: on_leave, holiday, rest, day_off, unscheduled or not_employed.
     */
    public function off(AttendanceStatus $status): static
    {
        return $this->state(fn () => ['status' => $status, 'scheduled_minutes' => 0, 'worked_minutes' => 0]);
    }
}
