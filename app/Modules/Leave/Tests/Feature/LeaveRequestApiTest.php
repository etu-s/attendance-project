<?php

declare(strict_types=1);

use App\Modules\Core\Events\LeaveChanged;
use App\Modules\Core\Tenancy\TenantSqliteDatabaseManager;
use App\Modules\Leave\Enums\LedgerKind;
use App\Modules\Leave\Models\LeaveLedgerEntry;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Tests\Support\LeaveTesting;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Scheduling\Models\Holiday;
use App\Modules\Scheduling\Services\HolidayService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class, LeaveTesting::class);

/*
 * "Now" is Wed 2026-03-04. E-1 works Mon–Fri and has 14 days (28 halves) of
 * annual leave for 2026.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-04 12:00:00', 'UTC'));
    $this->leaveWorkspace();
});

describe('counting and requesting', function (): void {
    it('counts scheduled working days only, skipping weekends and holidays', function (): void {
        $this->tenant->run(fn () => Holiday::factory()->create(['date' => '2026-03-11', 'name' => 'Mid-week holiday']));

        $this->actingAsTenantUser($this->worker)
            ->postJson($this->tenantUrl($this->tenant, 'leave-requests/preview'), $this->leaveBody('2026-03-07', '2026-03-15'))
            ->assertOk()
            ->assertJsonPath('data.days', 4)
            ->assertJsonPath('data.years.0.available', 14)
            ->assertJsonPath('data.years.0.after', 10)
            ->assertJsonPath('data.problem', null);

        $this->postJson($this->tenantUrl($this->tenant, 'leave-requests'), $this->leaveBody('2026-03-07', '2026-03-15', ['reason' => 'Trip']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.days', 4)
            ->assertJsonPath('data.can_cancel', true)
            ->assertJsonPath('data.can_decide', false);

        expect($this->balance($this->annual))->pending->toBe(8)->available->toBe(20)->balance->toBe(28);
    });

    it('counts half days at the edges and refuses a both-halves single day', function (): void {
        $this->actingAsTenantUser($this->worker);
        $url = $this->tenantUrl($this->tenant, 'leave-requests/preview');

        $this->postJson($url, $this->leaveBody('2026-03-09', '2026-03-10', ['half_start' => true, 'half_end' => true]))
            ->assertJsonPath('data.days', 1)
            ->assertJsonPath('data.counted_days.0.portion', 'second_half')
            ->assertJsonPath('data.counted_days.1.portion', 'first_half');

        $this->postJson($url, $this->leaveBody('2026-03-09', '2026-03-09', ['half_start' => true, 'half_end' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('half_end');
    });

    it('refuses overlaps but allows the other half of a day', function (): void {
        $this->actingAsTenantUser($this->worker);
        $url = $this->tenantUrl($this->tenant, 'leave-requests');

        $this->postJson($url, $this->leaveBody('2026-03-09', '2026-03-09', ['half_end' => true]))->assertCreated();
        $this->postJson($url, $this->leaveBody('2026-03-09', '2026-03-09', ['half_end' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('start_date');
        $this->postJson($url, $this->leaveBody('2026-03-09', '2026-03-10'))->assertUnprocessable();
        $this->postJson($url, $this->leaveBody('2026-03-09', '2026-03-09', ['half_start' => true]))->assertCreated();
    });

    it('enforces the balance down to the negative limit, but not for unlimited types', function (): void {
        $this->actingAsTenantUser($this->worker);
        $url = $this->tenantUrl($this->tenant, 'leave-requests');

        // 15 working days (2026-03-09 .. 2026-03-27) with 14 available.
        $this->postJson($url, $this->leaveBody('2026-03-09', '2026-03-27'))
            ->assertUnprocessable()->assertJsonValidationErrors('start_date');

        $this->tenant->run(fn () => $this->annual->update(['negative_limit_halves' => 2]));
        $this->postJson($url, $this->leaveBody('2026-03-09', '2026-03-27'))->assertCreated();
        expect($this->balance($this->annual)['available'])->toBe(-2);

        $this->postJson($url, $this->leaveBody('2026-04-01', '2026-06-30', ['leave_type_id' => $this->unpaid->id]))->assertCreated();
        expect($this->balance($this->unpaid)['available'])->toBeNull();
    });

    it('refuses ranges without working days, inactive types and other people', function (): void {
        $this->actingAsTenantUser($this->worker);
        $url = $this->tenantUrl($this->tenant, 'leave-requests');

        $this->postJson($url, $this->leaveBody('2026-03-07', '2026-03-08'))->assertUnprocessable();
        $this->tenant->run(fn () => $this->annual->update(['is_active' => false]));
        $this->postJson($url, $this->leaveBody('2026-03-09', '2026-03-09'))->assertUnprocessable()->assertJsonValidationErrors('leave_type_id');

        $other = $this->tenant->run(fn () => Employee::factory()->inDepartment(Department::factory()->create())->create());
        $this->postJson($url, $this->leaveBody('2026-03-09', '2026-03-09', ['employee_id' => $other->id]))->assertForbidden();
    });
});

describe('approval', function (): void {
    it('lets the manager approve, debiting the ledger and announcing the change', function (): void {
        Event::fake([LeaveChanged::class]);
        $id = $this->actingAsTenantUser($this->worker)
            ->postJson($this->tenantUrl($this->tenant, 'leave-requests'), $this->leaveBody('2026-03-09', '2026-03-11'))
            ->json('data.id');

        $this->postJson($this->tenantUrl($this->tenant, "leave-requests/{$id}/approve"))->assertForbidden();

        $this->actingAsTenantUser($this->manager)
            ->getJson($this->tenantUrl($this->tenant, 'leave-requests?status=pending'))
            ->assertJsonPath('data.0.can_decide', true);
        $this->postJson($this->tenantUrl($this->tenant, "leave-requests/{$id}/approve"), ['note' => 'Enjoy'])
            ->assertOk()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.decision_note', 'Enjoy');
        $this->postJson($this->tenantUrl($this->tenant, "leave-requests/{$id}/reject"))->assertStatus(409);

        expect($this->balance($this->annual))->used->toBe(6)->balance->toBe(22)->pending->toBe(0);
        Event::assertDispatched(LeaveChanged::class, fn (LeaveChanged $e) => $e->employeeId === $this->employee->id && $e->from === '2026-03-09');
    });

    it('never lets a manager decide their own leave', function (): void {
        $mine = $this->tenant->run(fn () => Employee::factory()->inDepartment($this->department)->create(['user_id' => $this->manager->id, 'hire_date' => '2026-01-01']));
        $this->accrue();

        $id = $this->actingAsTenantUser($this->manager)
            ->postJson($this->tenantUrl($this->tenant, 'leave-requests'), $this->leaveBody('2026-03-09', '2026-03-09', ['employee_id' => $mine->id]))
            ->assertCreated()->assertJsonPath('data.status', 'pending')->json('data.id');

        $this->postJson($this->tenantUrl($this->tenant, "leave-requests/{$id}/approve"))->assertForbidden()->assertJsonPath('rule', 'leave_own');
        $this->actingAsTenantUser($this->hr)->postJson($this->tenantUrl($this->tenant, "leave-requests/{$id}/reject"), ['note' => 'Busy week'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');
    });

    it('approves leave entered by HR at once', function (): void {
        $this->actingAsTenantUser($this->hr)
            ->postJson($this->tenantUrl($this->tenant, 'leave-requests'), $this->leaveBody('2026-03-09', '2026-03-09'))
            ->assertCreated()->assertJsonPath('data.status', 'approved')->assertJsonPath('data.decided_by.id', $this->hr->id);

        expect($this->balance($this->annual)['used'])->toBe(2);
    });
});

describe('cancelling', function (): void {
    it('lets the employee cancel pending and future approved leave, crediting it back', function (): void {
        $this->actingAsTenantUser($this->worker);
        $pending = $this->postJson($this->tenantUrl($this->tenant, 'leave-requests'), $this->leaveBody('2026-03-09', '2026-03-09'))->json('data.id');
        $this->postJson($this->tenantUrl($this->tenant, "leave-requests/{$pending}/cancel"))->assertOk()->assertJsonPath('data.status', 'cancelled');

        $approved = $this->actingAsTenantUser($this->hr)
            ->postJson($this->tenantUrl($this->tenant, 'leave-requests'), $this->leaveBody('2026-03-16', '2026-03-17'))->json('data.id');
        expect($this->balance($this->annual)['balance'])->toBe(24);

        $this->actingAsTenantUser($this->worker)->postJson($this->tenantUrl($this->tenant, "leave-requests/{$approved}/cancel"))->assertOk();
        expect($this->balance($this->annual))->balance->toBe(28)->used->toBe(0);
        $this->postJson($this->tenantUrl($this->tenant, "leave-requests/{$approved}/cancel"))->assertStatus(409);
    });

    it('leaves started approved leave to HR', function (): void {
        $id = $this->actingAsTenantUser($this->hr)
            ->postJson($this->tenantUrl($this->tenant, 'leave-requests'), $this->leaveBody('2026-03-02', '2026-03-04'))->json('data.id');

        $this->actingAsTenantUser($this->worker)->postJson($this->tenantUrl($this->tenant, "leave-requests/{$id}/cancel"))
            ->assertForbidden()->assertJsonPath('rule', 'leave_not_cancellable');
        $this->actingAsTenantUser($this->hr)->postJson($this->tenantUrl($this->tenant, "leave-requests/{$id}/cancel"))->assertOk();

        $kinds = $this->tenant->run(fn () => LeaveLedgerEntry::query()->where('leave_request_id', $id)->pluck('amount_halves', 'kind')->all());
        expect($kinds)->toBe([LedgerKind::Request->value => -6, LedgerKind::Cancellation->value => 6]);
    });
});

it('recounts approved leave when a holiday is added inside it', function (): void {
    $id = $this->actingAsTenantUser($this->hr)
        ->postJson($this->tenantUrl($this->tenant, 'leave-requests'), $this->leaveBody('2026-03-16', '2026-03-20'))->json('data.id');
    expect($this->balance($this->annual)['used'])->toBe(10);

    $this->tenant->run(fn () => app(HolidayService::class)->create(['date' => '2026-03-18', 'name' => 'Added later']));

    expect($this->balance($this->annual)['used'])->toBe(8)
        ->and($this->tenant->run(fn () => LeaveRequest::query()->find($id)->days_halves))->toBe(8)
        ->and($this->tenant->run(fn () => LeaveLedgerEntry::query()->where('kind', LedgerKind::Recount->value)->value('amount_halves')))->toBe(2);
});

it('lists requests by visibility, with mine and the team calendar', function (): void {
    $stranger = $this->tenant->run(fn () => Employee::factory()->inDepartment(Department::factory()->create())->create(['hire_date' => '2026-01-01']));
    $this->accrue();
    $this->actingAsTenantUser($this->hr);
    $this->postJson($this->tenantUrl($this->tenant, 'leave-requests'), $this->leaveBody('2026-03-09', '2026-03-09'))->assertCreated();
    $this->postJson($this->tenantUrl($this->tenant, 'leave-requests'), $this->leaveBody('2026-03-10', '2026-03-10', ['employee_id' => $stranger->id]))->assertCreated();

    $this->getJson($this->tenantUrl($this->tenant, 'leave-requests'))->assertJsonCount(2, 'data');
    $this->actingAsTenantUser($this->manager)->getJson($this->tenantUrl($this->tenant, 'leave-requests'))->assertJsonCount(1, 'data');
    $this->actingAsTenantUser($this->worker)->getJson($this->tenantUrl($this->tenant, 'leave-requests?mine=1'))
        ->assertJsonCount(1, 'data')->assertJsonPath('data.0.employee.employee_code', 'E-1');

    $this->actingAsTenantUser($this->manager)->getJson($this->tenantUrl($this->tenant, 'leave-calendar?from=2026-03-01&to=2026-03-31'))
        ->assertOk()->assertJsonCount(1, 'data');
    $this->actingAsTenantUser($this->worker)->getJson($this->tenantUrl($this->tenant, 'leave-calendar?from=2026-03-01&to=2026-03-31'))->assertForbidden();
});

it('keeps tenant B\'s leave out of tenant A', function (): void {
    $acme = $this->tenant;
    $this->leaveWorkspace('globex');
    $this->actingAsTenantUser($this->hr)
        ->postJson($this->tenantUrl($this->tenant, 'leave-requests'), $this->leaveBody('2026-03-09', '2026-03-09', ['reason' => 'GLOBEX-ONLY']))
        ->assertCreated();

    $pdo = new PDO('sqlite:'.app(TenantSqliteDatabaseManager::class)->path($acme->database()->getName()));
    expect((int) $pdo->query("select count(*) from leave_requests where reason = 'GLOBEX-ONLY'")->fetchColumn())->toBe(0)
        ->and((int) $pdo->query('select count(*) from leave_requests')->fetchColumn())->toBe(0);
    $pdo = null;
});
