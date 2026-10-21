<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Requests;

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Http\Requests\IndexRequest;
use Illuminate\Validation\Rule;

/**
 * Calculated days of the viewer's employees. `status` may be one value or a
 * comma-separated list (e.g. late,late_early). `search` matches the
 * employee's name or code.
 */
class IndexAttendanceRequest extends IndexRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('attendance.view') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('status'))) {
            $this->merge(['status' => array_values(array_filter(explode(',', $this->input('status'))))]);
        }
    }

    protected function sortable(): array
    {
        return [
            'work_date' => 'work_date',
            'worked_minutes' => 'worked_minutes',
            'late_minutes' => 'late_minutes',
            'first_in_at' => 'first_in_at',
        ];
    }

    protected function defaultSort(): string
    {
        return '-work_date';
    }

    protected function filterRules(): array
    {
        $date = 'date_format:'.LocalDate::FORMAT;

        return [
            'date' => ['sometimes', $date],
            'from' => ['sometimes', $date],
            'to' => ['sometimes', $date],
            'status' => ['sometimes', 'array'],
            'status.*' => [Rule::enum(AttendanceStatus::class)],
            'employee_id' => ['sometimes', 'integer', 'min:1'],
            'department_id' => ['sometimes', 'integer', 'min:1'],
            'group_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
