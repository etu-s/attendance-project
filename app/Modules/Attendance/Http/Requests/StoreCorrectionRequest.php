<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Requests;

use App\Modules\Attendance\Enums\CorrectionKind;
use App\Modules\Core\Casts\LocalDate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * add: a missed punch at punched_at (ISO 8601 with offset).
 * ignore: exclude one of the employee's raw punches.
 * Whether the caller may see the employee is checked by the controller.
 */
class StoreCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('corrections.request') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'min:1'],
            'work_date' => ['required', 'date_format:'.LocalDate::FORMAT],
            'kind' => ['required', Rule::enum(CorrectionKind::class)],
            'punched_at' => [
                'exclude_unless:kind,add',
                'required',
                'string',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d{1,6})?(Z|[+-]\d{2}:?\d{2})$/',
                'date',
            ],
            'raw_punch_id' => ['exclude_unless:kind,ignore', 'required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }
}
