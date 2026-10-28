<?php

declare(strict_types=1);

namespace App\Modules\Overtime\Http\Requests;

use App\Modules\Core\Casts\LocalDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A date range of at most $maxDays (62 to read one employee, 92 to recalculate).
 * Who may see or recalculate which employee is checked by the controller.
 */
class OvertimeRangeRequest extends FormRequest
{
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
            'employee_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        $max = $this->routeIs('*.recalculate') ? 92 : 62;

        return [function (Validator $validator) use ($max): void {
            if ($validator->errors()->isEmpty() && $this->localDate('from')->diffInDays($this->localDate('to')) >= $max) {
                $validator->errors()->add('to', "At most {$max} days at a time.");
            }
        }];
    }

    public function localDate(string $key): CarbonImmutable
    {
        return LocalDate::parse((string) $this->input($key));
    }
}
