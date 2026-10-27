<?php

declare(strict_types=1);

use App\Modules\Core\Services\SettingsService;
use App\Modules\Leave\Enums\LedgerKind;
use App\Modules\Leave\Models\LeaveLedgerEntry;
use App\Modules\Leave\Models\LeaveType;
use App\Modules\Leave\Services\LeaveLedger;
use App\Modules\Leave\Tests\Support\LeaveTesting;
use App\Modules\Organization\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class, LeaveTesting::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-04 12:00:00', 'UTC'));
    $this->leaveWorkspace();
});

it('grants the yearly entitlement once, prorated for joiners', function (): void {
    expect($this->accrue())->toBe(0)
        ->and($this->balance($this->annual)['entitled'])->toBe(28);

    $joiner = $this->tenant->run(fn () => Employee::factory()->inDepartment($this->department)->create(['hire_date' => '2026-04-10']));
    $this->travelTo(CarbonImmutable::parse('2026-04-15 12:00:00', 'UTC'));
    $this->accrue();

    // April to December = 9 months: 28 × 9 / 12 = 21 half-days.
    expect($this->balance($this->annual, employee: $joiner)['entitled'])->toBe(21)
        ->and($this->balance($this->unpaid, employee: $joiner)['entitled'])->toBe(0);
});

it('accrues monthly in cumulative twelfths that add up to the year', function (): void {
    $monthly = $this->tenant->run(fn () => LeaveType::factory()->monthly(25)->create(['code' => 'MO']));

    $this->accrue();
    // Months 1–3: 2 + 2 + 2 (25 × 3 / 12 = 6.25, rounded down).
    expect($this->balance($monthly)['entitled'])->toBe(6);

    $this->travelTo(CarbonImmutable::parse('2026-12-15 12:00:00', 'UTC'));
    $this->accrue();
    $this->accrue();
    expect($this->balance($monthly)['entitled'])->toBe(25);
});

it('carries up to the cap into the new year and lets the rest expire', function (): void {
    $this->tenant->run(fn () => app(LeaveLedger::class)->write($this->employee->id, $this->annual->id, 2026, LedgerKind::Request, -4));
    $debtor = $this->tenant->run(fn () => Employee::factory()->inDepartment($this->department)->create(['hire_date' => '2026-01-01']));
    $this->accrue();
    $this->tenant->run(fn () => app(LeaveLedger::class)->write($debtor->id, $this->annual->id, 2026, LedgerKind::Adjustment, -30, null, 'Advance'));

    $this->travelTo(CarbonImmutable::parse('2027-01-02 12:00:00', 'UTC'));
    $this->accrue();
    $this->accrue();

    // 24 left, cap 10: 10 carried, 14 expired; the new year adds its 28.
    expect($this->balance($this->annual, 2026))->expired->toBe(14)->balance->toBe(10)
        ->and($this->balance($this->annual, 2027))->entitled->toBe(38)->balance->toBe(38)
        // A negative balance moves in full and nothing expires.
        ->and($this->balance($this->annual, 2027, $debtor)['balance'])->toBe(28 - 2)
        ->and($this->balance($this->annual, 2026, $debtor)['expired'])->toBe(0);
});

it('follows the configured leave year start', function (): void {
    $this->tenant->run(fn () => app(SettingsService::class)->update(['leave.year_start_month' => 4]));
    $joiner = $this->tenant->run(fn () => Employee::factory()->inDepartment($this->department)->create(['hire_date' => '2026-02-01']));
    $this->accrue();

    // 2025 leave year (Apr 2025 – Mar 2026): hired in month 11 → 2 months → 28 × 2 / 12 = 4.
    expect($this->balance($this->annual, 2025, $joiner)['entitled'])->toBe(4);
});

it('keeps the ledger append-only', function (): void {
    $this->tenant->run(function (): void {
        expect(fn () => LeaveLedgerEntry::query()->firstOrFail()->update(['amount_halves' => 99]))->toThrow(LogicException::class)
            ->and(fn () => DB::table('leave_ledger')->update(['amount_halves' => 99]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('leave_ledger')->delete())->toThrow(QueryException::class);
    });
});

it('runs from the scheduled command', function (): void {
    $this->tenant->run(fn () => Employee::factory()->inDepartment($this->department)->create(['hire_date' => '2026-02-01']));

    $this->artisan('leave:accrue')->assertSuccessful();
    $this->artisan('leave:accrue')->assertSuccessful();

    expect($this->tenant->run(fn () => LeaveLedgerEntry::query()->where('kind', LedgerKind::Grant->value)->count()))->toBe(2);
});
