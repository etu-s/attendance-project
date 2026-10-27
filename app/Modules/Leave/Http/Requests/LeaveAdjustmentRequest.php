<?php

declare(strict_types=1);

namespace App\Modules\Leave\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A manual credit (positive days) or debit (negative), in half-day steps.
 */
class LeaveAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('leave.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'employee_id' => ['required', 'integer', 'min:1'],
            'leave_type_id' => ['required', 'integer', 'exists:leave_types,id'],
            'days' => ['required', 'numeric', 'between:-366,366', 'multiple_of:0.5', 'not_in:0'],
            'leave_year' => ['sometimes', 'integer', 'between:2000,2100'],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ];
    }

    public function halves(): int
    {
        return (int) round((float) $this->validated('days') * 2);
    }
}
