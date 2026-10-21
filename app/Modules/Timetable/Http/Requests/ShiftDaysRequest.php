<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The whole cycle: [{day_index, timetable_ids}]. Days not listed are rest
 * days. Overlaps and cycle bounds are checked by ShiftService.
 */
class ShiftDaysRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('shifts.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'days' => ['present', 'array', 'max:56'],
            'days.*.day_index' => ['required', 'integer', 'min:0', 'distinct'],
            'days.*.timetable_ids' => ['present', 'array'],
            'days.*.timetable_ids.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * @return list<array{day_index: int, timetable_ids: list<int>}>
     */
    public function days(): array
    {
        return array_values(array_map(fn (array $day) => [
            'day_index' => (int) $day['day_index'],
            'timetable_ids' => array_values(array_map('intval', $day['timetable_ids'])),
        ], (array) $this->validated('days')));
    }
}
