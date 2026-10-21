<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Http\Requests;

use App\Modules\Core\Http\Requests\IndexRequest;
use App\Modules\Timetable\Enums\TimetableType;
use Illuminate\Validation\Rule;

class IndexTimetablesRequest extends IndexRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('timetables.view') ?? false;
    }

    protected function sortable(): array
    {
        return ['name' => 'name', 'code' => 'code', 'start_minute' => 'start_minute', 'created_at' => 'created_at'];
    }

    protected function defaultSort(): string
    {
        return 'name';
    }

    protected function filterRules(): array
    {
        return [
            'type' => ['sometimes', Rule::enum(TimetableType::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
