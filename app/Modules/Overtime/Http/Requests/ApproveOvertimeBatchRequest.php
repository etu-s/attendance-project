<?php

declare(strict_types=1);

namespace App\Modules\Overtime\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApproveOvertimeBatchRequest extends FormRequest
{
    public const MAX = 200;

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
            'ids' => ['required', 'array', 'list', 'min:1', 'max:'.self::MAX],
            'ids.*' => ['integer', 'distinct', 'min:1'],
        ];
    }
}
