<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Http\Requests\Concerns\ValidatesRoleAssignment;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    use ValidatesRoleAssignment;

    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            // Checks soft-deleted rows too: a deleted user's email stays reserved (see users migration).
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'password' => ['required', 'string', Password::min(8)->letters()->numbers()],
            'role' => ['required', 'string', $this->assignableRoles()],
            'status' => ['sometimes', Rule::enum(UserStatus::class)],
        ];
    }
}
