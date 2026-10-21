<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Organization\Models\Group;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST) and partial update (PUT) of a group.
 */
class GroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('groups.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $group = $this->route('group');
        $editing = $group instanceof Group;
        $required = $editing ? 'sometimes' : 'required';
        $ignore = $editing ? $group->id : null;

        return [
            'name' => [$required, 'string', 'max:120'],
            'code' => [$required, 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique(Group::class, 'code')->ignore($ignore)],
            'priority' => [$required, 'integer', 'min:1', 'max:1000000', Rule::unique(Group::class, 'priority')->ignore($ignore)],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['priority.unique' => 'Another group already has this priority. Priorities are unique so precedence is never ambiguous.'];
    }
}
