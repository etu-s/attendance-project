<?php

declare(strict_types=1);

namespace App\Modules\Central\Http\Requests;

use App\Modules\Central\Enums\TenantStatus;
use App\Modules\Central\Models\Tenant;
use App\Modules\Core\Http\Requests\IndexRequest;
use Illuminate\Validation\Rule;

class IndexTenantsRequest extends IndexRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Tenant::class) ?? false;
    }

    protected function sortable(): array
    {
        return ['name' => 'name', 'status' => 'status', 'created_at' => 'created_at'];
    }

    protected function defaultSort(): string
    {
        return '-created_at';
    }

    protected function filterRules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(TenantStatus::class)],
            'plan_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
