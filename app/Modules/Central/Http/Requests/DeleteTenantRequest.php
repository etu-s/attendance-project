<?php

declare(strict_types=1);

namespace App\Modules\Central\Http\Requests;

use App\Modules\Central\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deletion is irreversible, so the caller must echo the tenant's subdomain
 * (`confirm`), the same "type the name to confirm" the UI asks for.
 */
class DeleteTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tenant = $this->route('tenant');

        return $tenant instanceof Tenant && ($this->user()?->can('delete', $tenant) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Tenant $tenant */
        $tenant = $this->route('tenant');

        return [
            'confirm' => ['required', 'string', Rule::in([$tenant->subdomain()])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['confirm.in' => 'Type the tenant subdomain exactly to confirm deletion.'];
    }
}
