<?php

declare(strict_types=1);

namespace App\Modules\Overtime\Http\Requests;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Overtime\Enums\OvertimeKind;
use App\Modules\Overtime\Models\OvertimeRecord;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A manual overtime entry (create: POST, partial update: PUT while pending).
 */
class OvertimeEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('overtime.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = ! $this->route('overtimeRecord') instanceof OvertimeRecord;
        $required = $creating ? 'required' : 'sometimes';

        return [
            'employee_id' => $creating ? ['required', 'integer', 'min:1'] : ['prohibited'],
            'work_date' => $creating ? ['required', 'date_format:'.LocalDate::FORMAT] : ['prohibited'],
            'minutes' => [$required, 'integer', 'min:1', 'max:1440'],
            'kind' => [$required, Rule::enum(OvertimeKind::class)],
            'reason' => [$required, 'string', 'min:3', 'max:255'],
        ];
    }
}
