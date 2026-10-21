<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Requests;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Http\Requests\IndexRequest;
use App\Modules\Scheduling\Enums\ScheduleTarget;
use Illuminate\Validation\Rule;

class IndexScheduleAssignmentsRequest extends IndexRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('schedules.view') ?? false;
    }

    protected function sortable(): array
    {
        return ['effective_from' => 'effective_from', 'created_at' => 'created_at'];
    }

    protected function defaultSort(): string
    {
        return '-effective_from';
    }

    protected function filterRules(): array
    {
        return [
            'target_type' => ['sometimes', Rule::enum(ScheduleTarget::class)],
            'target_id' => ['sometimes', 'integer', 'min:1'],
            'shift_id' => ['sometimes', 'integer', 'min:1'],
            'active_on' => ['sometimes', 'date_format:'.LocalDate::FORMAT],
        ];
    }
}
