<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IndexHolidaysRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('holidays.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'location_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
