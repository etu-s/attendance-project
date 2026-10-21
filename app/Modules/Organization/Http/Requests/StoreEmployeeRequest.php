<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Organization\Enums\EmploymentType;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\Group;
use App\Modules\Organization\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreEmployeeRequest extends FormRequest
{
    /** Allowed in employee codes; codes also arrive from devices, so keep them plain. */
    public const CODE_PATTERN = '/^[A-Za-z0-9._-]+$/';

    public function authorize(): bool
    {
        return $this->user()?->can('create', Employee::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Unique checks include soft-deleted employees: a code or email is never reused.
            'employee_code' => ['required', 'string', 'max:30', 'regex:'.self::CODE_PATTERN, Rule::unique(Employee::class, 'employee_code')],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['nullable', 'required_if_accepted:create_login', 'email', 'max:255', Rule::unique(Employee::class, 'email')],
            'phone' => ['nullable', 'string', 'max:30'],
            'job_title' => ['nullable', 'string', 'max:120'],
            'employment_type' => ['required', Rule::enum(EmploymentType::class)],
            'hire_date' => ['required', 'date_format:'.LocalDate::FORMAT],
            'department_id' => ['required', 'integer', Rule::exists(Department::class, 'id')->where('is_active', true)],
            'location_id' => ['nullable', 'integer', Rule::exists(Location::class, 'id')->where('is_active', true)],
            'group_ids' => ['sometimes', 'array', 'max:20'],
            'group_ids.*' => ['integer', 'distinct', Rule::exists(Group::class, 'id')->where('is_active', true)],
            'create_login' => ['sometimes', 'boolean'],
            'login_password' => ['exclude_unless:create_login,true,1', 'required', 'string', Password::min(8)->letters()->numbers()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'employee_code.regex' => 'Use letters, numbers, dots, dashes or underscores only.',
            'email.required_if_accepted' => 'An email is required to create a login.',
            'department_id.exists' => 'Choose an active department.',
            'location_id.exists' => 'Choose an active location.',
        ];
    }
}
