<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Uniqueness (across identifiers and employee codes) is checked by
 * PunchIdentifierService.
 */
class PunchIdentifierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('punches.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:64', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'label' => ['sometimes', 'nullable', 'string', 'max:60'],
        ];
    }
}
