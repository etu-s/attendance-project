<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Requests;

use App\Modules\Core\Casts\LocalDate;
use App\Modules\Scheduling\Enums\ScheduleTarget;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Target existence and shift activity are checked by ScheduleAssignmentService.
 */
class StoreScheduleAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('schedules.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $date = 'date_format:'.LocalDate::FORMAT;

        return [
            'target_type' => ['required', Rule::enum(ScheduleTarget::class)],
            'target_id' => ['present', 'nullable', 'integer', 'min:1'],
            'shift_id' => ['required', 'integer', 'min:1'],
            'effective_from' => ['required', $date],
            'effective_to' => ['sometimes', 'nullable', $date, 'after_or_equal:effective_from'],
            'anchor_date' => ['sometimes', 'nullable', $date],
        ];
    }

    public function target(): ScheduleTarget
    {
        return ScheduleTarget::from((string) $this->validated('target_type'));
    }

    public function effectiveFrom(): CarbonImmutable
    {
        return LocalDate::parse((string) $this->validated('effective_from'));
    }

    public function localDate(string $key): ?CarbonImmutable
    {
        $value = $this->validated($key);

        return $value === null ? null : LocalDate::parse((string) $value);
    }
}
