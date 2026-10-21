<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Requests;

use App\Modules\Core\Casts\LocalDate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class AttendanceSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('attendance.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:'.LocalDate::FORMAT],
            'department_id' => ['sometimes', 'integer', 'min:1'],
            'group_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function workDate(): CarbonImmutable
    {
        return LocalDate::parse((string) $this->validated('date'));
    }
}
