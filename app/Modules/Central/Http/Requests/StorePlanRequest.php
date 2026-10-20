<?php

declare(strict_types=1);

namespace App\Modules\Central\Http\Requests;

use App\Modules\Central\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Plan::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'slug' => ['required', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique(Plan::class, 'slug')],
            'max_employees' => ['present', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'features' => ['sometimes', 'array', 'max:50'],
            'features.*' => ['string', 'max:50', 'distinct'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
