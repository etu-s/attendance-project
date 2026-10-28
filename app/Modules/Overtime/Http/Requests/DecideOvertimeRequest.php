<?php

declare(strict_types=1);

namespace App\Modules\Overtime\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approve (optionally fewer minutes) or reject, with an optional note.
 */
class DecideOvertimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('overtime.approve') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
