<?php

declare(strict_types=1);

namespace App\Modules\Leave\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DecideLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('leave.approve') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['note' => ['sometimes', 'nullable', 'string', 'max:255']];
    }
}
