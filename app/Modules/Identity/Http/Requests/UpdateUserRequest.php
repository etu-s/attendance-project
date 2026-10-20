<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Http\Requests\Concerns\ValidatesRoleAssignment;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    use ValidatesRoleAssignment;

    public function authorize(): bool
    {
        $user = $this->route('user');

        return $user instanceof User && ($this->user()?->can('update', $user) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'email' => ['sometimes', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($user->id)],
            // Leave empty to keep the current password.
            'password' => ['sometimes', 'nullable', 'string', Password::min(8)->letters()->numbers()],
            'role' => ['sometimes', 'string', $this->assignableRoles()],
            'status' => ['sometimes', Rule::enum(UserStatus::class)],
        ];
    }
}
