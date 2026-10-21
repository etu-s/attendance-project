<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Requests;

use App\Modules\Core\Casts\LocalDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Employees × days. Employees are paginated (and scoped by
 * EmployeeVisibility); days are 1–31 from `from`.
 */
class RosterRequest extends FormRequest
{
    public const MAX_DAYS = 31;

    public function authorize(): bool
    {
        return $this->user()?->can('schedules.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:'.LocalDate::FORMAT],
            'days' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_DAYS],
            'department_id' => ['sometimes', 'integer', 'min:1'],
            'group_id' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function from(): CarbonImmutable
    {
        return LocalDate::parse((string) $this->validated('from'));
    }

    public function to(): CarbonImmutable
    {
        return $this->from()->addDays((int) ($this->validated('days') ?? 7) - 1);
    }
}
