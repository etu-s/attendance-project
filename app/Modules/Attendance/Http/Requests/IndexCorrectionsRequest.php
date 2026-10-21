<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Requests;

use App\Modules\Attendance\Enums\CorrectionKind;
use App\Modules\Attendance\Enums\CorrectionStatus;
use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Http\Requests\IndexRequest;
use Illuminate\Validation\Rule;

/**
 * Corrections of the employees the viewer may see (employees: their own).
 */
class IndexCorrectionsRequest extends IndexRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->can('corrections.request') || $user->can('corrections.approve'));
    }

    protected function sortable(): array
    {
        return ['created_at' => 'created_at', 'work_date' => 'work_date'];
    }

    protected function defaultSort(): string
    {
        return '-created_at';
    }

    protected function filterRules(): array
    {
        $date = 'date_format:'.LocalDate::FORMAT;

        return [
            'status' => ['sometimes', Rule::enum(CorrectionStatus::class)],
            'kind' => ['sometimes', Rule::enum(CorrectionKind::class)],
            'employee_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', $date],
            'to' => ['sometimes', $date],
        ];
    }
}
