<?php

declare(strict_types=1);

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Models\OrphanPunch;
use App\Modules\Attendance\Models\RawPunch;
use App\Modules\Attendance\Services\PunchSimulator;
use App\Modules\Attendance\Tests\Support\AttendanceTesting;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class, AttendanceTesting::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-06 20:00:00', 'UTC'));
    $this->attendanceWorkspace();
});

it('simulates punches from real schedules, repeatably', function (): void {
    $this->artisan('punches:simulate', ['--tenant' => 'acme', '--from' => '2026-03-02', '--to' => '2026-03-06', '--seed' => 7, '--missing-rate' => 0])
        ->assertSuccessful();

    $this->tenant->run(function (): void {
        // Two punches per working day for E-1 (Mon–Fri), plus the unknown badge.
        expect(RawPunch::query()->where('employee_identifier', 'E-1')->count())->toBeGreaterThanOrEqual(10)
            ->and(OrphanPunch::query()->where('employee_identifier', PunchSimulator::UNKNOWN_BADGE)->count())->toBe(1)
            ->and(AttendanceDay::query()->where('employee_id', $this->employee->id)->whereBetween('work_date', ['2026-03-02', '2026-03-06'])->count())->toBe(5);
    });

    $before = $this->tenant->run(fn () => RawPunch::query()->count());
    $this->artisan('punches:simulate', ['--tenant' => 'acme', '--from' => '2026-03-02', '--to' => '2026-03-06', '--seed' => 7, '--missing-rate' => 0])
        ->expectsOutputToContain('Duplicates')
        ->assertSuccessful();
    expect($this->tenant->run(fn () => RawPunch::query()->count()))->toBe($before);
});

it('refuses --http without a key and unknown tenants', function (): void {
    $this->artisan('punches:simulate', ['--tenant' => 'acme', '--http' => true])->assertFailed();
    $this->artisan('punches:simulate', ['--tenant' => 'nope'])->assertFailed();
});

it('closes finished days without punches as absent', function (): void {
    $this->artisan('attendance:close-days')->assertSuccessful();

    expect($this->day('2026-03-05')->status)->toBe(AttendanceStatus::Absent)
        ->and($this->day('2026-03-06')->status)->toBe(AttendanceStatus::InProgress)
        ->and($this->day('2026-03-04')->status)->toBe(AttendanceStatus::Absent);
});
