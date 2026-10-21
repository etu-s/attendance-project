<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Requests;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Http\Requests\IndexRequest;

/**
 * The raw punch log. `from`/`to` are UTC dates of punched_at; `search`
 * matches the identifier as sent.
 */
class IndexRawPunchesRequest extends IndexRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('punches.view') ?? false;
    }

    protected function sortable(): array
    {
        return ['punched_at' => 'punched_at', 'received_at' => 'received_at'];
    }

    protected function defaultSort(): string
    {
        return '-punched_at';
    }

    protected function filterRules(): array
    {
        $date = 'date_format:'.LocalDate::FORMAT;

        return [
            'punch_source_id' => ['sometimes', 'integer', 'min:1'],
            'employee_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', $date],
            'to' => ['sometimes', $date],
            'state' => ['sometimes', 'in:matched,unmatched,too_old'],
        ];
    }
}
