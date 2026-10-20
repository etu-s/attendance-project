<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Core\Enums\RoleName;
use App\Modules\Core\Http\Requests\IndexRequest;
use App\Modules\Identity\Enums\UserStatus;
use App\Modules\Identity\Models\User;
use Illuminate\Validation\Rule;

class IndexUsersRequest extends IndexRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', User::class) ?? false;
    }

    protected function sortable(): array
    {
        return [
            'name' => 'name',
            'email' => 'email',
            'status' => 'status',
            'created_at' => 'created_at',
            'last_login_at' => 'last_login_at',
        ];
    }

    protected function defaultSort(): string
    {
        return 'name';
    }

    protected function filterRules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(UserStatus::class)],
            'role' => ['sometimes', Rule::enum(RoleName::class)],
        ];
    }
}
