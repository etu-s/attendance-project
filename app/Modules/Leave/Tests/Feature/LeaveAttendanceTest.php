<?php

declare(strict_types=1);

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Leave\Models\LeaveRequest;
use App\Modules\Leave\Tests\Support\LeaveTesting;
use App\Modules\Scheduling\Models\Holiday;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class, LeaveTesting::class);

/*
 * Office 09:00–17:00 UTC with lunch 13:00–14:00; midpoint 13:00. Now is Wed
 * 2026-03-04, so Monday and Tuesday are finished days.
 */
beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-04 12:00:00', 'UTC'));
    $this->leaveWorkspace();
});

it('turns an absent day into leave when sick leave is entered afterwards', function (): void {
    $this->recalculate('2026-03-02', '2026-03-02');
    expect($this->day('2026-03-02')->status)->toBe(AttendanceStatus::Absent);

    $this->approveLeave('2026-03-02', '2026-03-02');

    expect($this->day('2026-03-02'))->status->toBe(AttendanceStatus::OnLeave)
        ->leave_name->toBe('Annual leave')
        ->leave_portion->toBe('full')
        ->scheduled_minutes->toBe(0);
});

it('keeps a full day of leave as leave even with punches, recording the work', function (): void {
    $this->approveLeave('2026-03-02', '2026-03-02');
    $this->punch('E-1', '2026-03-02 09:00', '2026-03-02 12:00');

    expect($this->day('2026-03-02'))->status->toBe(AttendanceStatus::OnLeave)->worked_minutes->toBe(180)->late_minutes->toBe(0);
});

it('evaluates the other half of a half-day leave from the midpoint', function (): void {
    // First half off: expected from 13:00; 13:04 is within grace.
    $this->approveLeave('2026-03-02', '2026-03-02', ['half_end' => true]);
    $this->punch('E-1', '2026-03-02 13:04', '2026-03-02 17:01');

    // Second half off: due 09:00–13:00; no punches is absent.
    $this->approveLeave('2026-03-03', '2026-03-03', ['half_start' => true]);
    $this->recalculate('2026-03-03', '2026-03-03');

    expect($this->day('2026-03-02'))->status->toBe(AttendanceStatus::Present)
        ->late_minutes->toBe(0)
        ->flags->toBe(['half_day_leave'])
        ->scheduled_minutes->toBe(210)
        ->leave_portion->toBe('first_half')
        ->and($this->day('2026-03-03'))->status->toBe(AttendanceStatus::Absent)->leave_portion->toBe('second_half');
});

it('counts late from the midpoint after a morning off', function (): void {
    $this->approveLeave('2026-03-02', '2026-03-02', ['half_end' => true]);
    $this->punch('E-1', '2026-03-02 13:20', '2026-03-02 17:00');

    expect($this->day('2026-03-02'))->status->toBe(AttendanceStatus::Late)->late_minutes->toBe(20);
});

it('neither deducts nor shows leave on a holiday inside it', function (): void {
    $this->tenant->run(fn () => Holiday::factory()->create(['date' => '2026-03-03', 'name' => 'Holiday']));
    $this->approveLeave('2026-03-02', '2026-03-03');
    $this->recalculate('2026-03-02', '2026-03-03');

    expect($this->balance($this->annual)['used'])->toBe(2)
        ->and($this->day('2026-03-03'))->status->toBe(AttendanceStatus::Holiday)->leave_name->toBeNull()
        ->and($this->day('2026-03-02')->status)->toBe(AttendanceStatus::OnLeave);
});

it('restores attendance when approved leave is cancelled', function (): void {
    $this->punch('E-1', '2026-03-02 09:00', '2026-03-02 17:00');
    $this->approveLeave('2026-03-02', '2026-03-02');
    expect($this->day('2026-03-02')->status)->toBe(AttendanceStatus::OnLeave);

    $id = $this->tenant->run(fn () => LeaveRequest::query()->value('id'));
    $this->actingAsTenantUser($this->hr)->postJson($this->tenantUrl($this->tenant, "leave-requests/{$id}/cancel"))->assertOk();

    expect($this->day('2026-03-02')->status)->toBe(AttendanceStatus::Present);
});
