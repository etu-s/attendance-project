<?php

declare(strict_types=1);

namespace App\Modules\Leave\Http\Requests;

use App\Modules\Core\Casts\LocalDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Who is off between two dates (at most 62 days).
 */
class LeaveCalendarRequest extends FormRequest
{
    public const MAX_DAYS = 62;

    public function authorize(): bool
    {
        return $this->user()?->can('leave.view') ?? false;
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
            'department_id' => ['sometimes', 'integer', 'min:1'],
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
