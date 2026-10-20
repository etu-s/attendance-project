<?php

declare(strict_types=1);

namespace App\Modules\Central\Http\Requests;

use App\Modules\Central\Models\Domain;
use App\Modules\Central\Models\Plan;
use App\Modules\Central\Models\Tenant;
use App\Modules\Core\Tenancy\TenantProvisioningData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreTenantRequest extends FormRequest
{
    /** A single DNS label: lowercase letters, digits and inner hyphens, 2-63 chars. */
    public const SUBDOMAIN_PATTERN = '/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])$/';

    public function authorize(): bool
    {
        return $this->user()?->can('create', Tenant::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('subdomain'))) {
            $this->merge(['subdomain' => mb_strtolower(trim($this->input('subdomain')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'subdomain' => [
                'required', 'string', 'min:2', 'max:63',
                'regex:'.self::SUBDOMAIN_PATTERN,
                Rule::notIn((array) config('ams.reserved_subdomains')),
                Rule::unique(Domain::class, 'domain'),
            ],
            'plan_id' => ['required', 'integer', Rule::exists(Plan::class, 'id')->where('is_active', true)],
            'timezone' => ['required', 'string', 'timezone:all'],
            'admin_name' => ['required', 'string', 'max:120'],
            'admin_email' => ['required', 'string', 'email', 'max:255'],
            'admin_password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->numbers()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subdomain.regex' => 'Use lowercase letters, numbers and hyphens only (no leading or trailing hyphen).',
            'subdomain.not_in' => 'This subdomain is reserved.',
        ];
    }

    public function toData(): TenantProvisioningData
    {
        return new TenantProvisioningData(
            name: (string) $this->validated('name'),
            subdomain: (string) $this->validated('subdomain'),
            planId: (int) $this->validated('plan_id'),
            timezone: (string) $this->validated('timezone'),
            adminName: (string) $this->validated('admin_name'),
            adminEmail: mb_strtolower((string) $this->validated('admin_email')),
            adminPassword: (string) $this->validated('admin_password'),
        );
    }
}
