<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Requests;

use App\Modules\Core\Casts\LocalDate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shift, cycle anchor and end date only; target and start are fixed.
 */
class UpdateScheduleAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('schedules.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $date = 'date_format:'.LocalDate::FORMAT;

        return [
            'shift_id' => ['sometimes', 'integer', 'min:1'],
            'anchor_date' => ['sometimes', $date],
            'effective_to' => ['sometimes', 'nullable', $date],
        ];
    }
}
