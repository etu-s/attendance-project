<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Organization\Enums\EmployeeStatus;
use App\Modules\Organization\Enums\EmploymentType;
use App\Modules\Organization\Models\Employee;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Profile fields only. Department/location changes go through transfer (they
 * are dated), termination through terminate, groups through join/leave.
 */
class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');

        return $employee instanceof Employee && ($this->user()?->can('update', $employee) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Employee $employee */
        $employee = $this->route('employee');

        return [
            'employee_code' => ['sometimes', 'string', 'max:30', 'regex:'.StoreEmployeeRequest::CODE_PATTERN,
                Rule::unique(Employee::class, 'employee_code')->ignore($employee->id)],
            'first_name' => ['sometimes', 'string', 'max:80'],
            'last_name' => ['sometimes', 'string', 'max:80'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255', Rule::unique(Employee::class, 'email')->ignore($employee->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'job_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'employment_type' => ['sometimes', Rule::enum(EmploymentType::class)],
            'hire_date' => ['sometimes', 'date_format:'.LocalDate::FORMAT],
            // Terminated is reached only through the terminate endpoint.
            'status' => ['sometimes', Rule::in([EmployeeStatus::Active->value, EmployeeStatus::Inactive->value])],
        ];
    }
}
