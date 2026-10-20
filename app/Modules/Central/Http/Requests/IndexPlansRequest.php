<?php

declare(strict_types=1);

namespace App\Modules\Central\Http\Requests;

use App\Modules\Central\Models\Plan;
use App\Modules\Core\Http\Requests\IndexRequest;

class IndexPlansRequest extends IndexRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Plan::class) ?? false;
    }

    protected function sortable(): array
    {
        return ['name' => 'name', 'created_at' => 'created_at', 'max_employees' => 'max_employees'];
    }

    protected function defaultSort(): string
    {
        return 'name';
    }

    protected function filterRules(): array
    {
        return [
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
