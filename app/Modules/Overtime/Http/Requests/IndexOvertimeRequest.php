<?php

declare(strict_types=1);

namespace App\Modules\Overtime\Http\Requests;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Http\Requests\IndexRequest;
use App\Modules\Overtime\Enums\OvertimeSource;
use App\Modules\Overtime\Enums\OvertimeStatus;
use Illuminate\Validation\Rule;

/**
 * Overtime records (and, on /overtime/summary, per-employee totals) of the
 * viewer's employees. `search` matches the employee's name or code.
 */
class IndexOvertimeRequest extends IndexRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('overtime.view') ?? false;
    }

    protected function sortable(): array
    {
        return ['work_date' => 'work_date', 'calculated_minutes' => 'calculated_minutes'];
    }

    protected function defaultSort(): string
    {
        return '-work_date';
    }

    protected function filterRules(): array
    {
        $date = 'date_format:'.LocalDate::FORMAT;

        return [
            'status' => ['sometimes', Rule::enum(OvertimeStatus::class)],
            'source' => ['sometimes', Rule::enum(OvertimeSource::class)],
            'employee_id' => ['sometimes', 'integer', 'min:1'],
            'department_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', $date],
            'to' => ['sometimes', $date],
            'changed' => ['sometimes', 'boolean'],
        ];
    }
}
