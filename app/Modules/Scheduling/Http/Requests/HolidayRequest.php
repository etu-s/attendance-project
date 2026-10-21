<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Requests;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Organization\Models\Location;
use App\Modules\Scheduling\Models\Holiday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST) and partial update (PUT). Duplicate date + scope is checked
 * by HolidayService (SQLite unique indexes treat NULL scopes as distinct).
 */
class HolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('holidays.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->route('holiday') instanceof Holiday ? 'sometimes' : 'required';

        return [
            'date' => [$required, 'date_format:'.LocalDate::FORMAT],
            'name' => [$required, 'string', 'max:120'],
            'location_id' => ['sometimes', 'nullable', 'integer', Rule::exists(Location::class, 'id')],
            'recurs_yearly' => ['sometimes', 'boolean'],
        ];
    }
}
