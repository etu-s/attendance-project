<?php

declare(strict_types=1);

namespace App\Modules\Central\Http\Requests;

use App\Modules\Central\Models\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        $plan = $this->route('plan');

        return $plan instanceof Plan && ($this->user()?->can('update', $plan) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Plan $plan */
        $plan = $this->route('plan');

        return [
            'name' => ['sometimes', 'string', 'max:100'],
            'slug' => ['sometimes', 'string', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique(Plan::class, 'slug')->ignore($plan->id)],
            'max_employees' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1000000'],
            'features' => ['sometimes', 'array', 'max:50'],
            'features.*' => ['string', 'max:50', 'distinct'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
