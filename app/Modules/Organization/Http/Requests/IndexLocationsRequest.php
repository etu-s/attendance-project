<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Core\Http\Requests\IndexRequest;

class IndexLocationsRequest extends IndexRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('locations.view') ?? false;
    }

    protected function sortable(): array
    {
        return ['name' => 'name', 'code' => 'code', 'created_at' => 'created_at'];
    }

    protected function defaultSort(): string
    {
        return 'name';
    }

    protected function filterRules(): array
    {
        return ['is_active' => ['sometimes', 'boolean']];
    }
}
