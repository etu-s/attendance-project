<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LinkOrphanPunchRequest extends FormRequest
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
            'employee_id' => ['required', 'integer', 'exists:employees,id,deleted_at,NULL'],
        ];
    }
}
