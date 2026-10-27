<?php

declare(strict_types=1);

namespace App\Modules\Leave\Http\Requests;

use App\Modules\Leave\Enums\LeaveAccrual;
use App\Modules\Leave\Models\LeaveType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create (POST) and partial update (PUT). Amounts are in days with half-day
 * steps; they are stored as half-days (toAttributes()).
 */
class LeaveTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('leave.manage') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $type = $this->route('leaveType');
        $required = $type instanceof LeaveType ? 'sometimes' : 'required';
        $days = fn (int $max) => ['numeric', 'min:0', "max:{$max}", 'multiple_of:0.5'];

        return [
            'name' => [$required, 'string', 'max:80'],
            'code' => [$required, 'string', 'max:20', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('leave_types', 'code')->ignore($type instanceof LeaveType ? $type->id : null)],
            'color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_paid' => ['sometimes', 'boolean'],
            'accrual' => ['sometimes', Rule::enum(LeaveAccrual::class)],
            'entitlement_days' => ['sometimes', ...$days(366)],
            'carry_forward_max_days' => ['sometimes', 'nullable', ...$days(366)],
            'negative_limit_days' => ['sometimes', ...$days(366)],
            'unlimited' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        $data = $this->validated();
        $halves = fn (mixed $days): ?int => $days === null ? null : (int) round((float) $days * 2);

        foreach (['entitlement_days' => 'entitlement_halves', 'carry_forward_max_days' => 'carry_forward_max_halves', 'negative_limit_days' => 'negative_limit_halves'] as $from => $to) {
            if (array_key_exists($from, $data)) {
                $data[$to] = $halves($data[$from]);
                unset($data[$from]);
            }
        }

        return $data;
    }
}
