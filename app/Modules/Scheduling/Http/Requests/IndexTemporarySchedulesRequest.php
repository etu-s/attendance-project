<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Requests;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Http\Requests\IndexRequest;

/**
 * All temporary schedules across employees; `search` matches the employee's
 * name or code.
 */
class IndexTemporarySchedulesRequest extends IndexRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('schedules.view') ?? false;
    }

    protected function sortable(): array
    {
        return ['date_from' => 'date_from', 'created_at' => 'created_at'];
    }

    protected function defaultSort(): string
    {
        return '-date_from';
    }

    protected function filterRules(): array
    {
        return [
            'employee_id' => ['sometimes', 'integer', 'min:1'],
            'active_on' => ['sometimes', 'date_format:'.LocalDate::FORMAT],
            'kind' => ['sometimes', 'in:shift,day_off'],
        ];
    }
}
