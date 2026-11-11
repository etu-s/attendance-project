<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Setting a password from an emailed link (reset or invitation). The
 * password rule matches creating a user.
 */
class SetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }

    /**
     * @return array{email: string, token: string, password: string, password_confirmation: string}
     */
    public function credentials(): array
    {
        /** @var array{email: string, token: string, password: string} $data */
        $data = $this->validated();

        return [
            'email' => mb_strtolower($data['email']),
            'token' => $data['token'],
            'password' => $data['password'],
            'password_confirmation' => (string) $this->input('password_confirmation'),
        ];
    }
}
