<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Http\Requests;

use App\Modules\Core\Http\Requests\IndexRequest;

class IndexShiftsRequest extends IndexRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shifts.view') ?? false;
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
