<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Requests;

use App\Modules\Core\Casts\LocalDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Queue a recalculation of up to 92 days, for one employee or everyone.
 */
class RecalculateAttendanceRequest extends FormRequest
{
    public const MAX_DAYS = 92;

    public function authorize(): bool
    {
        return $this->user()?->can('attendance.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $date = 'date_format:'.LocalDate::FORMAT;

        return [
            'from' => ['required', $date],
            'to' => ['required', $date, 'after_or_equal:from'],
            'employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && $this->localDate('from')->diffInDays($this->localDate('to')) >= self::MAX_DAYS) {
                $validator->errors()->add('to', 'Recalculate at most '.self::MAX_DAYS.' days at a time.');
            }
        }];
    }

    public function localDate(string $key): CarbonImmutable
    {
        return LocalDate::parse((string) $this->input($key));
    }
}
