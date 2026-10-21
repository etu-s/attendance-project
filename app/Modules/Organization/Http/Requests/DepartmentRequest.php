<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Organization\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST) and partial update (PUT) of a department. Cycles are rejected
 * by DepartmentService, since they depend on the whole tree.
 */
class DepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('departments.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $department = $this->route('department');
        $editing = $department instanceof Department;
        $required = $editing ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:120'],
            'code' => [$required, 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique(Department::class, 'code')->ignore($editing ? $department->id : null)],
            'parent_id' => ['sometimes', 'nullable', 'integer', Rule::exists(Department::class, 'id')],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
