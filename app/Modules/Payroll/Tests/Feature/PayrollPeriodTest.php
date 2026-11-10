<?php

declare(strict_types=1);

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Core\Contracts\PeriodLock;
use App\Modules\Core\Exceptions\BusinessRuleException;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Services\SettingsService;
use App\Modules\Core\Tenancy\TenantSqliteDatabaseManager;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Tests\Support\LeaveTesting;
use App\Modules\Overtime\Enums\OvertimeStatus;
use App\Modules\Overtime\Models\OvertimeRecord;
use App\Modules\Overtime\Services\OvertimeService;
use App\Modules\Payroll\Models\PayrollPeriod;
use App\Modules\Scheduling\Models\Holiday;
use App\Modules\Scheduling\Models\ScheduleAssignment;
use App\Modules\Scheduling\Services\HolidayService;
use App\Modules\Scheduling\Services\ScheduleAssignmentService;
use App\Modules\Scheduling\Services\TemporaryScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class, LeaveTesting::class);

/*
 * LeaveTesting's office workspace (Mon–Fri 09:00–17:00 from 2026-01-01, E-1,
 * HR, a manager, E-1's login). Today is Friday 2026-03-06.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-06 12:00:00', 'UTC'));
    $this->leaveWorkspace();
    $this->admin = $this->tenantAdmin($this->tenant);
    $this->periods = $this->tenantUrl($this->tenant, 'payroll-periods');
    $this->february = ['name' => 'February 2026', 'start_date' => '2026-02-01', 'end_date' => '2026-02-28'];
});

function lockFebruary(): int
{
    return (int) test()->actingAsTenantUser(test()->hr)->postJson(test()->periods, test()->february)->assertCreated()->json('data.id');
}

describe('locking and unlocking', function (): void {
    it('lets HR lock finished days and only a Tenant Admin unlock, with a reason', function (): void {
        $this->actingAsTenantUser($this->hr)
            ->postJson($this->periods, $this->february)
            ->assertCreated()
            ->assertJsonPath('data.status', 'locked')
            ->assertJsonPath('data.locked_by.id', $this->hr->id);
        $id = $this->tenant->run(fn () => PayrollPeriod::query()->value('id'));

        $this->postJson($this->periods, ['name' => 'Overlap', 'start_date' => '2026-02-20', 'end_date' => '2026-03-01'])
            ->assertStatus(409)->assertJsonPath('rule', 'period_overlap');
        $this->postJson($this->periods, ['name' => 'This week', 'start_date' => '2026-03-02', 'end_date' => '2026-03-06'])
            ->assertJsonValidationErrors('end_date');
        $this->postJson($this->periods, ['name' => 'Too long', 'start_date' => '2025-01-01', 'end_date' => '2026-01-31'])
            ->assertJsonValidationErrors('end_date');
        $this->postJson("{$this->periods}/{$id}/unlock", ['reason' => 'Late correction'])->assertForbidden();

        $this->actingAsTenantUser($this->admin);
        $this->postJson("{$this->periods}/{$id}/unlock", [])->assertJsonValidationErrors('reason');
        $this->postJson("{$this->periods}/{$id}/unlock", ['reason' => 'Late correction'])
            ->assertOk()
            ->assertJsonPath('data.status', 'unlocked')
            ->assertJsonPath('data.unlock_reason', 'Late correction')
            ->assertJsonPath('data.unlocked_by.id', $this->admin->id);
        $this->postJson("{$this->periods}/{$id}/unlock", ['reason' => 'Again'])->assertStatus(409)->assertJsonPath('rule', 'period_not_locked');

        $this->actingAsTenantUser($this->hr)->postJson("{$this->periods}/{$id}/lock")
            ->assertOk()->assertJsonPath('data.status', 'locked')->assertJsonPath('data.unlock_reason', null);
        $this->postJson("{$this->periods}/{$id}/lock")->assertStatus(409)->assertJsonPath('rule', 'period_already_locked');

        $this->getJson($this->periods)->assertOk()->assertJsonCount(1, 'data');
        $this->getJson("{$this->periods}?status=unlocked")->assertJsonCount(0, 'data');
        $this->actingAsTenantUser($this->manager)->getJson($this->periods)->assertForbidden();
        $this->actingAsTenantUser($this->worker)->postJson($this->periods, $this->february)->assertForbidden();

        $this->tenant->run(fn () => expect(AuditLog::query()->where('auditable_type', 'payroll_period')->count())->toBe(3));
    });
});

describe('frozen days', function (): void {
    it('stops recalculation inside a locked period and resumes after unlocking', function (): void {
        // Friday 27 February: no punches, so absent.
        $this->recalculate('2026-02-27', '2026-02-27');
        expect($this->day('2026-02-27')->status)->toBe(AttendanceStatus::Absent);

        $id = lockFebruary();
        // A device uploads the day late: stored, but the day stays as it was.
        $this->punch('E-1', '2026-02-27 09:00', '2026-02-27 17:00');
        $this->tenant->run(fn () => app(SettingsService::class)->update(['attendance.late_grace_minutes' => 30]));
        $this->recalculate('2026-02-01', '2026-03-05');
        expect($this->day('2026-02-27')->status)->toBe(AttendanceStatus::Absent)
            ->and($this->day('2026-03-02')->status)->toBe(AttendanceStatus::Absent);

        // Unlocking recalculates the period (sync queue in tests).
        $this->actingAsTenantUser($this->admin)->postJson("{$this->periods}/{$id}/unlock", ['reason' => 'Device was offline'])->assertOk();
        expect($this->day('2026-02-27')->status)->toBe(AttendanceStatus::Present);
    });

    it('refuses attendance, leave and overtime changes that touch a locked day', function (): void {
        $this->recalculate('2026-02-23', '2026-02-27');
        $record = $this->tenant->run(fn () => OvertimeRecord::factory()->create(['employee_id' => $this->employee->id, 'work_date' => '2026-02-25']));
        $this->approveLeave('2026-02-16', '2026-02-17');
        lockFebruary();

        $this->actingAsTenantUser($this->hr);
        $this->postJson($this->tenantUrl($this->tenant, 'punch-corrections'), [
            'employee_id' => $this->employee->id, 'work_date' => '2026-02-26', 'kind' => 'add',
            'punched_at' => '2026-02-26T17:00:00Z', 'reason' => 'Forgot',
        ])->assertStatus(409)->assertJsonPath('rule', 'period_locked')
            ->assertJsonPath('message', fn (string $m) => str_contains($m, '"February 2026"'));
        $this->postJson($this->tenantUrl($this->tenant, 'leave-requests'), $this->leaveBody('2026-02-26', '2026-03-03'))
            ->assertStatus(409)->assertJsonPath('rule', 'period_locked');
        $leave = $this->tenant->run(fn () => LeaveRequest::query()->where('status', LeaveRequestStatus::Approved->value)->value('id'));
        $this->postJson($this->tenantUrl($this->tenant, "leave-requests/{$leave}/cancel"))->assertStatus(409);
        $this->postJson($this->tenantUrl($this->tenant, "overtime/{$record->id}/approve"))->assertStatus(409)->assertJsonPath('rule', 'period_locked');
        $this->postJson($this->tenantUrl($this->tenant, 'overtime/approve-batch'), ['ids' => [$record->id]])
            ->assertOk()->assertJsonPath('data.approved', 0)->assertJsonPath('data.skipped', 1);
        $this->postJson($this->tenantUrl($this->tenant, 'overtime-entries'), [
            'employee_id' => $this->employee->id, 'work_date' => '2026-02-20', 'minutes' => 60, 'kind' => 'workday', 'reason' => 'Stocktake',
        ])->assertStatus(409);

        // Outside the period everything still works.
        $this->postJson($this->tenantUrl($this->tenant, 'leave-requests'), $this->leaveBody('2026-03-09', '2026-03-09'))->assertCreated();
        $this->tenant->run(function () use ($record): void {
            expect($record->refresh()->status)->toBe(OvertimeStatus::Pending);
            $march = OvertimeRecord::factory()->create(['employee_id' => $this->employee->id, 'work_date' => '2026-03-03']);
            expect(app(OvertimeService::class)->approve($march, $this->hr, null, null)->status)->toBe(OvertimeStatus::Approved);
        });
    });

    it('refuses schedule and holiday changes that reach a locked day, and only those', function (): void {
        lockFebruary();
        $locked = fn (callable $change) => expect($change)->toThrow(fn (BusinessRuleException $e) => expect($e->rule)->toBe('period_locked'));

        $this->tenant->run(function () use ($locked): void {
            $assignments = app(ScheduleAssignmentService::class);
            $holidays = app(HolidayService::class);
            $default = ScheduleAssignment::query()->firstOrFail();

            $locked(fn () => $assignments->assign($default->target_type, null, $this->shift->id, CarbonImmutable::parse('2026-02-15')));
            $locked(fn () => $assignments->update($default, ['anchor_date' => '2026-01-12']));
            $locked(fn () => $assignments->delete($default));
            // Ending the open-ended default after the period only changes later days.
            expect($assignments->update($default, ['effective_to' => '2026-04-30'])->effective_to->format('Y-m-d'))->toBe('2026-04-30');
            $locked(fn () => $assignments->update($default, ['effective_to' => '2026-02-10']));

            $locked(fn () => app(TemporaryScheduleService::class)->create($this->employee, ['date_from' => '2026-02-27', 'date_to' => '2026-03-03']));
            $locked(fn () => $holidays->create(['date' => '2026-02-20', 'name' => 'Founders day']));
            $locked(fn () => $holidays->create(['date' => '2025-02-20', 'name' => 'Yearly', 'recurs_yearly' => true]));
            expect($holidays->create(['date' => '2025-12-25', 'name' => 'Christmas', 'recurs_yearly' => true]))->toBeInstanceOf(Holiday::class)
                ->and($holidays->create(['date' => '2026-03-23', 'name' => 'Pakistan Day']))->toBeInstanceOf(Holiday::class);
        });
    });
});

it('lists locked periods on reports', function (): void {
    lockFebruary();

    $this->actingAsTenantUser($this->hr)
        ->getJson($this->tenantUrl($this->tenant, 'reports/dashboard?from=2026-02-20&to=2026-03-05'))
        ->assertOk()
        ->assertJsonPath('data.locked_periods.0.name', 'February 2026');
    $this->getJson($this->tenantUrl($this->tenant, 'reports/attendance-summary?from=2026-03-01&to=2026-03-05'))
        ->assertJsonCount(0, 'meta.locked_periods');
});

it('keeps tenant B\'s payroll periods out of tenant A', function (): void {
    $acme = $this->tenant;
    lockFebruary();

    $this->leaveWorkspace('globex');
    $this->tenant->run(function (): void {
        expect(app(PeriodLock::class)->lockedDates(CarbonImmutable::parse('2026-02-01'), CarbonImmutable::parse('2026-02-28')))->toBe([]);
        PayrollPeriod::factory()->between('2026-01-01', '2026-01-31', 'Globex January')->create();
    });

    $pdo = new PDO('sqlite:'.app(TenantSqliteDatabaseManager::class)->path($acme->database()->getName()));
    expect((int) $pdo->query("select count(*) from payroll_periods where name = 'Globex January'")->fetchColumn())->toBe(0)
        ->and((int) $pdo->query('select count(*) from payroll_periods')->fetchColumn())->toBe(1);
    $pdo = null;
});
