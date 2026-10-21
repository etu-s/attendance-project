<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Http\Requests;

use App\Modules\Core\Casts\LocalDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Preview of a shift on real dates. `anchor` is the date the cycle starts
 * (defaults to `from`), `timezone` defaults to the workspace timezone.
 */
class ShiftPreviewRequest extends FormRequest
{
    public const MAX_DAYS = 62;

    public function authorize(): bool
    {
        return $this->user()?->can('shifts.view') ?? false;
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
            'anchor' => ['sometimes', $date],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if ($this->localDate('from')->diffInDays($this->localDate('to')) >= self::MAX_DAYS) {
                $validator->errors()->add('to', 'Preview at most '.self::MAX_DAYS.' days at a time.');
            }
        }];
    }

    public function localDate(string $key): CarbonImmutable
    {
        return LocalDate::parse((string) $this->input($key));
    }
}
