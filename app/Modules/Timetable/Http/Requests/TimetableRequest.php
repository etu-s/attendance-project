<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Http\Requests;

use App\Modules\Timetable\Enums\BreakKind;
use App\Modules\Timetable\Enums\TimetableType;
use App\Modules\Timetable\Models\Timetable;
use App\Modules\Timetable\Services\TimetableService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST) and partial update (PUT). Field formats only; rules spanning
 * fields (breaks inside the timetable, required time inside the window) are
 * checked by TimetableService. All times are whole minutes.
 */
class TimetableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('timetables.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $timetable = $this->route('timetable');
        $editing = $timetable instanceof Timetable;
        $required = $editing ? 'sometimes' : 'required';
        $optionalMinutes = fn (int $max) => ['sometimes', 'nullable', 'integer', 'min:0', "max:{$max}"];

        return [
            'name' => [$required, 'string', 'max:120'],
            'code' => [$required, 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique(Timetable::class, 'code')->ignore($editing ? $timetable->id : null)],
            'type' => [$required, Rule::enum(TimetableType::class)],
            'color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'start_minute' => [$required, 'integer', 'min:0', 'max:1439'],
            'duration_minutes' => [$required, 'integer', 'min:1', 'max:1440'],
            'required_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],
            'core_start_offset' => $optionalMinutes(1439),
            'core_duration_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],
            'late_grace_minutes' => $optionalMinutes(720),
            'early_leave_grace_minutes' => $optionalMinutes(720),
            'check_in_before_minutes' => $optionalMinutes(720),
            'check_out_after_minutes' => $optionalMinutes(720),
            'min_work_minutes' => $optionalMinutes(1440),
            'is_active' => ['sometimes', 'boolean'],
            'breaks' => ['sometimes', 'array', 'max:'.TimetableService::MAX_BREAKS],
            'breaks.*.kind' => ['required', Rule::enum(BreakKind::class)],
            'breaks.*.start_offset' => ['nullable', 'integer', 'min:0', 'max:1439'],
            'breaks.*.duration_minutes' => ['required', 'integer', 'min:1', 'max:720'],
            'breaks.*.is_paid' => ['sometimes', 'boolean'],
        ];
    }
}
