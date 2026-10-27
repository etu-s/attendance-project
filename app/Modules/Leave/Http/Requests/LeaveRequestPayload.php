<?php

declare(strict_types=1);

namespace App\Modules\Leave\Http\Requests;

use App\Modules\Core\Casts\LocalDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A leave request (create) or its preview. Range, half-day and balance rules
 * are checked by LeaveRequestService; seeing the employee by the controller.
 */
class LeaveRequestPayload extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('leave.request') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $date = 'date_format:'.LocalDate::FORMAT;

        return [
            'employee_id' => ['required', 'integer', 'min:1'],
            'leave_type_id' => ['required', 'integer', 'min:1'],
            'start_date' => ['required', $date],
            'end_date' => ['required', $date],
            'half_start' => ['sometimes', 'boolean'],
            'half_end' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function localDate(string $key): CarbonImmutable
    {
        return LocalDate::parse((string) $this->validated($key));
    }
}
