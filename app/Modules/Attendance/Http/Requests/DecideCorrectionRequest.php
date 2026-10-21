<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DecideCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('corrections.approve') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['note' => ['sometimes', 'nullable', 'string', 'max:255']];
    }
}
