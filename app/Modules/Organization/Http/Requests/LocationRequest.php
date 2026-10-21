<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Organization\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST) and partial update (PUT) of a location.
 */
class LocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('locations.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $location = $this->route('location');
        $editing = $location instanceof Location;
        $required = $editing ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:120'],
            'code' => [$required, 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique(Location::class, 'code')->ignore($editing ? $location->id : null)],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'timezone' => ['sometimes', 'nullable', 'string', 'timezone:all'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
