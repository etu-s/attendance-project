<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Requests;

use App\Modules\Core\Casts\LocalDate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /payroll-periods: lock a new period. Further rules (finished days
 * only, at most 366 days, no overlap) are in PayrollPeriodService.
 */
class LockPayrollPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payroll-periods.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $date = 'date_format:'.LocalDate::FORMAT;

        return [
            'name' => ['required', 'string', 'max:80'],
            'start_date' => ['required', $date],
            'end_date' => ['required', $date, 'after_or_equal:start_date'],
        ];
    }
}
