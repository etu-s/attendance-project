<?php

declare(strict_types=1);

namespace App\Modules\Reports\Reports;

use App\Modules\Attendance\Models\AttendanceDay;
use App\Modules\Attendance\Services\AttendanceQuery;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Identity\Models\User;
use App\Modules\Reports\Enums\ExceptionType;
use App\Modules\Reports\Enums\ReportKind;
use App\Modules\Reports\Support\EmployeeLookup;
use App\Modules\Reports\Support\ReportPage;
use App\Modules\Reports\Support\ReportParams;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

/**
 * Days that went wrong, one row per employee and day: late arrivals, early
 * departures, absences or missing punches (`type`).
 */
final class ExceptionsReport implements Report
{
    private const SORTS = [
        'work_date' => 'work_date',
        'late_minutes' => 'late_minutes',
        'early_leave_minutes' => 'early_leave_minutes',
        'worked_minutes' => 'worked_minutes',
    ];

    public function __construct(
        private readonly AttendanceQuery $attendance,
    ) {}

    public function kind(): ReportKind
    {
        return ReportKind::Exceptions;
    }

    public function rules(): array
    {
        return ['type' => ['required', Rule::enum(ExceptionType::class)]];
    }

    public function monthly(): bool
    {
        return false;
    }

    public function sortable(): array
    {
        return array_keys(self::SORTS);
    }

    public function defaultSort(): string
    {
        return '-work_date';
    }

    public function page(ReportParams $params, User $viewer, int $perPage, int $page): ReportPage
    {
        $type = ExceptionType::from((string) $params->extra['type']);
        [$key, $direction] = $params->sortOr($this->defaultSort());

        $paginator = $this->attendance->paginate(
            [...$params->filters(), 'status' => $type->statuses()],
            $params->search,
            [self::SORTS[$key], $direction],
            $perPage,
            $viewer,
            $page,
        );

        /** @var list<AttendanceDay> $days */
        $days = $paginator->items();
        $departments = EmployeeLookup::departments(array_map(fn (AttendanceDay $d) => (int) $d->department_id, $days));

        return ReportPage::of($paginator, array_map(fn (AttendanceDay $day): array => [
            'id' => $day->id,
            'employee' => [
                'id' => $day->employee->id,
                'full_name' => $day->employee->fullName(),
                'employee_code' => $day->employee->employee_code,
            ],
            'department' => $departments[(int) $day->department_id] ?? null,
            'work_date' => $day->work_date->format(LocalDate::FORMAT),
            'status' => $day->status->value,
            'timezone' => $day->timezone,
            'first_in_at' => $day->first_in_at?->toIso8601ZuluString(),
            'last_out_at' => $day->last_out_at?->toIso8601ZuluString(),
            'scheduled_minutes' => $day->scheduled_minutes,
            'worked_minutes' => $day->worked_minutes,
            'late_minutes' => $day->late_minutes,
            'early_leave_minutes' => $day->early_leave_minutes,
        ], $days), ['type' => $type->value]);
    }

    public function columns(ReportParams $params, ReportPage $firstPage): array
    {
        // Punch times in the day's own timezone, as people read them.
        $local = fn (array $r, string $key): ?string => $r[$key] === null ? null
            : CarbonImmutable::parse($r[$key])->setTimezone($r['timezone'])->format('Y-m-d H:i');

        return [
            'Date' => fn (array $r) => $r['work_date'],
            'Employee code' => fn (array $r) => $r['employee']['employee_code'],
            'Employee' => fn (array $r) => $r['employee']['full_name'],
            'Department' => fn (array $r) => $r['department']['name'] ?? null,
            'Status' => fn (array $r) => $r['status'],
            'First in' => fn (array $r) => $local($r, 'first_in_at'),
            'Last out' => fn (array $r) => $local($r, 'last_out_at'),
            'Timezone' => fn (array $r) => $r['timezone'],
            'Scheduled minutes' => fn (array $r) => $r['scheduled_minutes'],
            'Worked minutes' => fn (array $r) => $r['worked_minutes'],
            'Late minutes' => fn (array $r) => $r['late_minutes'],
            'Early leave minutes' => fn (array $r) => $r['early_leave_minutes'],
        ];
    }
}
