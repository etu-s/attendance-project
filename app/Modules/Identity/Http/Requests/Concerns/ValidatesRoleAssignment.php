<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests\Concerns;

use App\Modules\Core\Enums\RoleName;
use App\Modules\Identity\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

trait ValidatesRoleAssignment
{
    /**
     * Roles the current user may hand out: everything for a Tenant Admin,
     * everything except Tenant Admin for anyone else.
     */
    protected function assignableRoles(): In
    {
        /** @var User|null $actor */
        $actor = $this->user();

        $roles = array_map(fn (RoleName $role) => $role->value, RoleName::cases());

        if (! $actor?->isTenantAdmin()) {
            $roles = array_values(array_diff($roles, [RoleName::TenantAdmin->value]));
        }

        return Rule::in($roles);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['role.in' => 'You are not allowed to assign this role.'];
    }
}
