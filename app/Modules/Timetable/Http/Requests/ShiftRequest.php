<?php

declare(strict_types=1);

namespace App\Modules\Timetable\Http\Requests;

use App\Modules\Timetable\Enums\CycleUnit;
use App\Modules\Timetable\Models\Shift;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Create (POST) and partial update (PUT) of a shift's header. The cycle's
 * days are saved separately (ShiftDaysRequest).
 */
class ShiftRequest extends FormRequest
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
        $shift = $this->route('shift');
        $editing = $shift instanceof Shift;
        $required = $editing ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:120'],
            'code' => [$required, 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique(Shift::class, 'code')->ignore($editing ? $shift->id : null)],
            'cycle_unit' => [$required, Rule::enum(CycleUnit::class)],
            'cycle_length' => [$required, 'integer', 'min:1', 'max:'.CycleUnit::Day->maxLength()],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $shift = $this->route('shift');
            $unit = CycleUnit::tryFrom((string) ($this->input('cycle_unit') ?? ($shift instanceof Shift ? $shift->cycle_unit->value : '')));
            $length = (int) ($this->input('cycle_length') ?? ($shift instanceof Shift ? $shift->cycle_length : 0));

            if ($unit !== null && $length > $unit->maxLength()) {
                $validator->errors()->add('cycle_length', "A {$unit->value} cycle can be at most {$unit->maxLength()} {$unit->value}s long.");
            }
        }];
    }
}
