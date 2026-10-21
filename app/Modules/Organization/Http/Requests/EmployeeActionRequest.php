<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Requests;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Organization\Models\Department;
use App\Modules\Organization\Models\Employee;
use App\Modules\Organization\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the dated lifecycle actions on one employee. The route name
 * picks the rule set; date-order rules (after hire date, after termination)
 * live in the services because they depend on stored history.
 */
class EmployeeActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $employee = $this->route('employee');

        if (! $employee instanceof Employee) {
            return false;
        }

        $ability = in_array($this->action(), ['terminate', 'reinstate'], true) ? 'terminate' : 'update';

        return $this->user()?->can($ability, $employee) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $date = ['required', 'date_format:'.LocalDate::FORMAT];
        $department = Rule::exists(Department::class, 'id')->where('is_active', true);
        $location = Rule::exists(Location::class, 'id')->where('is_active', true);

        return match ($this->action()) {
            'transfer' => [
                'department_id' => ['required', 'integer', $department],
                'location_id' => ['present', 'nullable', 'integer', $location],
                'effective_from' => $date,
            ],
            'terminate' => [
                'termination_date' => $date,
                'deactivate_login' => ['sometimes', 'boolean'],
            ],
            'reinstate' => [
                'effective_from' => $date,
                'department_id' => ['sometimes', 'nullable', 'integer', $department],
                'location_id' => ['sometimes', 'nullable', 'integer', $location],
            ],
            'groups.join' => ['group_id' => ['required', 'integer'], 'effective_from' => $date],
            'groups.leave' => ['effective_to' => $date],
            'user' => ['user_id' => ['present', 'nullable', 'integer', 'min:1']],
            default => [],
        };
    }

    public function localDate(string $key): CarbonImmutable
    {
        return LocalDate::parse((string) $this->validated($key));
    }

    private function action(): string
    {
        return str_replace('tenant.employees.', '', (string) $this->route()?->getName());
    }
}
