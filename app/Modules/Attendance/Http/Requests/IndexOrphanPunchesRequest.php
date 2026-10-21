<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Requests;

use App\Modules\Attendance\Enums\OrphanStatus;
use App\Modules\Core\Http\Requests\IndexRequest;
use Illuminate\Validation\Rule;

/**
 * Unmatched punches; open ones unless `status` says otherwise. `search`
 * matches the identifier.
 */
class IndexOrphanPunchesRequest extends IndexRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('punches.view') ?? false;
    }

    protected function sortable(): array
    {
        return ['created_at' => 'created_at', 'employee_identifier' => 'employee_identifier'];
    }

    protected function defaultSort(): string
    {
        return '-created_at';
    }

    protected function filterRules(): array
    {
        return ['status' => ['sometimes', Rule::enum(OrphanStatus::class)]];
    }
}
