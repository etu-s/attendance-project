<?php

declare(strict_types=1);

namespace App\Modules\Leave\Http\Requests;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Core\Http\Requests\IndexRequest;
use App\Modules\Leave\Enums\LeaveRequestStatus;
use Illuminate\Validation\Rule;

/**
 * Leave requests the viewer may see; `mine=1` keeps only the viewer's own.
 * `search` matches the employee's name or code.
 */
class IndexLeaveRequestsRequest extends IndexRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->can('leave.request') || $user->can('leave.view'));
    }

    protected function sortable(): array
    {
        return ['start_date' => 'start_date', 'created_at' => 'created_at'];
    }

    protected function defaultSort(): string
    {
        return '-start_date';
    }

    protected function filterRules(): array
    {
        $date = 'date_format:'.LocalDate::FORMAT;

        return [
            'status' => ['sometimes', Rule::enum(LeaveRequestStatus::class)],
            'leave_type_id' => ['sometimes', 'integer', 'min:1'],
            'employee_id' => ['sometimes', 'integer', 'min:1'],
            'department_id' => ['sometimes', 'integer', 'min:1'],
            'from' => ['sometimes', $date],
            'to' => ['sometimes', $date],
            'mine' => ['sometimes', 'boolean'],
        ];
    }
}
