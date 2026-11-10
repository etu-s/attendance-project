<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /payroll-periods/{id}/unlock: Tenant Admin only, with a reason
 * (kept on the period and in the audit log).
 */
class UnlockPayrollPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('payroll-periods.unlock') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:255']];
    }
}
