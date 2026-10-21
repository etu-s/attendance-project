<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Requests;

use App\Modules\Core\Casts\LocalDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Calculated days for one employee over at most 62 days. Who may see which
 * employee is checked by the controller.
 */
class AttendanceRangeRequest extends FormRequest
{
    public const MAX_DAYS = 62;

    public function authorize(): bool
    {
        return $this->user() !== null;
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
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && $this->localDate('from')->diffInDays($this->localDate('to')) >= self::MAX_DAYS) {
                $validator->errors()->add('to', 'Show at most '.self::MAX_DAYS.' days at a time.');
            }
        }];
    }

    public function localDate(string $key): CarbonImmutable
    {
        return LocalDate::parse((string) $this->input($key));
    }
}
