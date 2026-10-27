<?php

declare(strict_types=1);

namespace App\Modules\Leave\Database\Seeders;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Enums\DayPortion;
use App\Modules\Core\Support\TenantClock;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Enums\LeaveAccrual;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveAccrualService;
use App\Modules\Leave\Services\LeaveDayCounter;
use App\Modules\Leave\Services\LeaveRequestService;
use App\Modules\Organization\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo tenant only (tenant context): annual, sick and unpaid leave, this
 * year's grants, and a few requests around today in every status. Approved
 * leave goes through LeaveRequestService (the demo admin approves at once);
 * pending and rejected requests are written with their counted days, as an
 * employee's own request would be.
 */
final class DemoLeaveSeeder extends Seeder
{
    public function run(LeaveRequestService $requests, LeaveDayCounter $counter, LeaveAccrualService $accrual, TenantClock $clock): void
    {
        $annual = LeaveType::query()->create([
            'name' => 'Annual leave', 'code' => 'AL', 'color' => '#2563eb', 'accrual' => LeaveAccrual::Yearly,
            'entitlement_halves' => 28, 'carry_forward_max_halves' => 10,
        ]);
        $sick = LeaveType::query()->create([
            'name' => 'Sick leave', 'code' => 'SL', 'color' => '#dc2626', 'accrual' => LeaveAccrual::Yearly,
            'entitlement_halves' => 20,
        ]);
        LeaveType::query()->create([
            'name' => 'Unpaid leave', 'code' => 'UL', 'color' => '#64748b', 'is_paid' => false, 'unlimited' => true,
        ]);

        $today = $clock->today();
        $accrual->run($today);

        $admin = User::query()->where('email', config('ams.seed.demo_admin_email'))->firstOrFail();
        $employee = fn (string $code): Employee => Employee::query()->where('employee_code', $code)->firstOrFail();

        // [code, type, search from (days from today), working days, half day at the end]
        $approved = [
            ['A-005', $sick, -10, -1, false],
            ['A-002', $annual, 7, 3, false],
            ['A-007', $annual, 1, 1, true],
        ];
        foreach ($approved as [$code, $type, $offset, $count, $half]) {
            $days = $this->workingDays($counter, $employee($code), $today->addDays($offset), $count);
            if ($days === []) {
                continue;
            }

            $requests->create($employee($code), [
                'leave_type_id' => $type->id,
                'start_date' => $days[0]['date'],
                'end_date' => $days[count($days) - 1]['date'],
                'half_end' => $half,
                'reason' => 'Demo',
            ], $admin);
        }

        $this->open($counter, $employee('A-003'), $annual, $today->addDays(14), 3, LeaveRequestStatus::Pending, 'Family wedding');
        $this->open($counter, $employee('A-006'), $annual, $today->addDays(3), 1, LeaveRequestStatus::Pending, 'Appointment');
        $this->open($counter, $employee('A-004'), $annual, $today->addDays(5), 2, LeaveRequestStatus::Rejected, 'Short notice');
    }

    /**
     * The first $count scheduled working days from $from (a negative $count:
     * the last working day before $from + 10 days, for leave in the past).
     *
     * @return list<array{date: string, portion: DayPortion, leave_year: int}>
     */
    private function workingDays(LeaveDayCounter $counter, Employee $employee, CarbonImmutable $from, int $count): array
    {
        $days = $counter->count($employee, $from, $from->addDays(20), false, false);

        return $count < 0
            ? array_slice(array_values(array_filter($days, fn (array $d) => LocalDate::parse($d['date'])->lessThan($from->addDays(10)))), $count)
            : array_slice($days, 0, $count);
    }

    private function open(LeaveDayCounter $counter, Employee $employee, LeaveType $type, CarbonImmutable $from, int $count, LeaveRequestStatus $status, string $reason): void
    {
        $days = $this->workingDays($counter, $employee, $from, $count);
        if ($days === []) {
            return;
        }
        $from = LocalDate::parse($days[0]['date']);
        $to = LocalDate::parse($days[count($days) - 1]['date']);

        $request = LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'leave_type_id' => $type->id,
            'start_date' => $from,
            'end_date' => $to,
            'days_halves' => array_sum(array_map(fn (array $d) => $d['portion']->halves(), $days)),
            'reason' => $reason,
            'status' => $status,
            'decision_note' => $status === LeaveRequestStatus::Rejected ? 'Too many people off that week' : null,
            'decided_at' => $status === LeaveRequestStatus::Rejected ? CarbonImmutable::now('UTC') : null,
        ]);

        DB::table('leave_request_days')->insert(array_map(fn (array $d) => [
            'leave_request_id' => $request->id,
            'employee_id' => $employee->id,
            'date' => $d['date'],
            'portion' => $d['portion']->value,
            'leave_year' => $d['leave_year'],
        ], $days));
    }
}
