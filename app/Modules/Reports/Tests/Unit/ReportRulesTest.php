<?php

declare(strict_types=1);

use App\Modules\Reports\Enums\ExceptionType;
use App\Modules\Reports\Export\CsvTableWriter;
use App\Modules\Reports\Support\Kpis;
use App\Modules\Reports\Support\ReportParams;

it('computes attendance rate and punctuality on working days only', function (): void {
    $kpis = Kpis::from([
        'present' => 5, 'late' => 2, 'early_leave' => 1, 'late_early' => 1, 'incomplete' => 1, 'absent' => 2,
        'on_leave' => 3, 'holiday' => 1, 'rest' => 4, 'day_off' => 1, 'unscheduled' => 1, 'in_progress' => 1,
        'late_days' => 3, 'late_minutes' => 47,
    ]);

    expect($kpis)
        ->attended_days->toBe(10)
        ->working_days->toBe(12)
        ->attendance_rate->toBe(83.3)
        ->punctuality_rate->toBe(70.0)
        ->early_leave_days->toBe(2)
        ->rest_days->toBe(6)
        ->in_progress_days->toBe(1);
});

it('has no rate when there is nothing to divide', function (): void {
    expect(Kpis::from([]))->attendance_rate->toBeNull()->punctuality_rate->toBeNull()
        ->and(Kpis::from(['absent' => 2]))->attendance_rate->toBe(0.0)->punctuality_rate->toBeNull();
});

it('maps exception types to attendance statuses', function (): void {
    expect(ExceptionType::Late->statuses())->toBe(['late', 'late_early'])
        ->and(ExceptionType::EarlyLeave->statuses())->toBe(['early_leave', 'late_early'])
        ->and(ExceptionType::Absent->statuses())->toBe(['absent']);
});

it('neutralises spreadsheet formulas in CSV text but not in numbers', function (): void {
    expect(CsvTableWriter::cell('=1+1'))->toBe("'=1+1")
        ->and(CsvTableWriter::cell('@SUM(A1)'))->toBe("'@SUM(A1)")
        ->and(CsvTableWriter::cell('-x'))->toBe("'-x")
        ->and(CsvTableWriter::cell(-5))->toBe(-5)
        ->and(CsvTableWriter::cell(null))->toBe('')
        ->and(CsvTableWriter::cell('Ada'))->toBe('Ada');
});

it('turns a month into its first and last day, and round-trips parameters', function (): void {
    $params = ReportParams::fromArray(['month' => '2026-02', 'department_id' => '4', 'search' => '  ', 'type' => 'late']);

    expect($params->from->format('Y-m-d'))->toBe('2026-02-01')
        ->and($params->to->format('Y-m-d'))->toBe('2026-02-28')
        ->and($params->days())->toBe(28)
        ->and($params->search)->toBeNull()
        ->and($params->toArray())->toBe(['from' => '2026-02-01', 'to' => '2026-02-28', 'department_id' => 4, 'type' => 'late'])
        ->and(ReportParams::fromArray($params->toArray())->toArray())->toBe($params->toArray())
        ->and($params->sortOr('-work_date'))->toBe(['work_date', 'desc']);
});
