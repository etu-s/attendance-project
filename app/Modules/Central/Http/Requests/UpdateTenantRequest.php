<?php

declare(strict_types=1);

namespace App\Modules\Central\Http\Requests;

use App\Modules\Central\Models\Plan;
use App\Modules\Central\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The subdomain is intentionally not editable: it is the tenant's identity
 * for sessions, devices and bookmarks. Changing it is a separate, future flow.
 */
class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = $this->route('tenant');

        return $tenant instanceof Tenant && ($this->user()?->can('update', $tenant) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'plan_id' => ['sometimes', 'integer', Rule::exists(Plan::class, 'id')->where('is_active', true)],
        ];
    }
}
