<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Core\Http\Requests\IndexRequest;
use App\Modules\Organization\Enums\EmployeeStatus;
use App\Modules\Organization\Enums\EmploymentType;
use App\Modules\Organization\Models\Employee;
use Illuminate\Validation\Rule;

class IndexEmployeesRequest extends IndexRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Employee::class) ?? false;
    }

    protected function sortable(): array
    {
        return [
            'name' => 'name',
            'employee_code' => 'employee_code',
            'hire_date' => 'hire_date',
            'status' => 'status',
            'created_at' => 'created_at',
        ];
    }

    protected function defaultSort(): string
    {
        return 'name';
    }

    protected function filterRules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(EmployeeStatus::class)],
            'employment_type' => ['sometimes', Rule::enum(EmploymentType::class)],
            'department_id' => ['sometimes', 'integer', 'min:1'],
            'location_id' => ['sometimes', 'integer', 'min:1'],
            'group_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }
}
