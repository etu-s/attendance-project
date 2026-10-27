<?php

declare(strict_types=1);

namespace App\Modules\Leave\Tests\Support;

use App\Modules\Attendance\Tests\Support\AttendanceTesting;
use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Support\TenantClock;
use App\Modules\Identity\Models\User;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveAccrualService;
use App\Modules\Leave\Services\LeaveBalanceService;
use App\Modules\Organization\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * On top of AttendanceTesting's office workspace (Mon–Fri 09:00–17:00, E-1):
 * annual leave (14 days a year, up to 5 carried), unpaid leave (unlimited),
 * an HR user, a manager of E-1's department, and E-1's own login.
 *
 * @property LeaveType $annual
 * @property LeaveType $unpaid
 * @property User $hr
 * @property User $manager
 * @property User $worker
 */
trait LeaveTesting
{
    use AttendanceTesting;

    protected function leaveWorkspace(string $subdomain = 'acme'): void
    {
        $this->attendanceWorkspace(subdomain: $subdomain);
        $this->hr = $this->createTenantUser($this->tenant, RoleName::HrManager);
        $this->manager = $this->createTenantUser($this->tenant, RoleName::DepartmentManager);
        $this->worker = $this->createTenantUser($this->tenant, RoleName::Employee);

        [$this->annual, $this->unpaid] = $this->tenant->run(function (): array {
            DB::table('department_managers')->insert(['department_id' => $this->department->id, 'user_id' => $this->manager->id]);
            $this->employee->update(['user_id' => $this->worker->id]);

            return [
                LeaveType::factory()->create(['name' => 'Annual leave', 'code' => 'AL']),
                LeaveType::factory()->unlimited()->create(['code' => 'UL']),
            ];
        });

        $this->accrue();
    }

    protected function accrue(): int
    {
        return $this->tenant->run(fn () => app(LeaveAccrualService::class)->run(app(TenantClock::class)->today()));
    }

    /**
     * Balance row of a type for an employee, in half-days.
     *
     * @return array{entitled: int, used: int, adjusted: int, expired: int, balance: int, pending: int, available: int|null}
     */
    protected function balance(LeaveType $type, int $year = 2026, ?Employee $employee = null): array
    {
        return $this->tenant->run(function () use ($type, $year, $employee): array {
            $row = collect(app(LeaveBalanceService::class)->forEmployee($employee ?? $this->employee, $year))
                ->firstOrFail(fn (array $b) => $b['type']->id === $type->id);
            unset($row['type']);

            return $row;
        });
    }

    /**
     * HR enters leave for E-1 through the API: approved at once.
     *
     * @param  array<string, mixed>  $overrides
     */
    protected function approveLeave(string $from, string $to, array $overrides = []): void
    {
        $this->actingAsTenantUser($this->hr)
            ->postJson($this->tenantUrl($this->tenant, 'leave-requests'), $this->leaveBody($from, $to, $overrides))
            ->assertCreated()
            ->assertJsonPath('data.status', 'approved');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function leaveBody(string $from, string $to, array $overrides = []): array
    {
        return $overrides + [
            'employee_id' => $this->employee->id,
            'leave_type_id' => $this->annual->id,
            'start_date' => $from,
            'end_date' => $to,
        ];
    }
}
