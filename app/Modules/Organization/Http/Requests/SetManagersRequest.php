<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Replaces the manager list of a department or a group. Whether the ids are
 * active users is checked by ManagerAssignments (users belong to Identity).
 */
class SetManagersRequest extends FormRequest
{
    public function authorize(): bool
    {
        $permission = $this->route('group') !== null ? 'groups.manage' : 'departments.manage';

        return $this->user()?->can($permission) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user_ids' => ['present', 'array', 'max:50'],
            'user_ids.*' => ['integer', 'distinct', 'min:1'],
        ];
    }

    /**
     * @return list<int>
     */
    public function userIds(): array
    {
        return array_values(array_map('intval', (array) $this->validated('user_ids')));
    }
}
