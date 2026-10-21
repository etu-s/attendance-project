<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Requests;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Scheduling\Models\TemporarySchedule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Create (POST, under an employee) and partial update (PUT). A missing or
 * null shift_id means "day off". Overlaps are checked by the service.
 * Whether the caller may see the employee is checked by the controller.
 */
class TemporaryScheduleRequest extends FormRequest
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
        $required = $this->route('temporarySchedule') instanceof TemporarySchedule ? 'sometimes' : 'required';
        $date = 'date_format:'.LocalDate::FORMAT;

        return [
            'date_from' => [$required, $date],
            'date_to' => [$required, $date],
            'shift_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'anchor_date' => ['sometimes', 'nullable', $date],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
