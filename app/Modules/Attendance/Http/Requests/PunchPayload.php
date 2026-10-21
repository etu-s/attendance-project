<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Requests;

use App\Modules\Attendance\Enums\PunchDirection;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Validation for one punch, shared by POST /punches and each item of
 * POST /punches/batch.
 *
 * punched_at must be ISO 8601 with an explicit offset (Z or ±hh:mm): a device
 * clock without a timezone cannot be placed on the right work date.
 */
final class PunchPayload
{
    /** Clock skew tolerated for punches "from the future". */
    public const FUTURE_TOLERANCE_MINUTES = 5;

    public const MAX_META_BYTES = 2048;

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'employee_identifier' => ['required', 'string', 'min:1', 'max:64', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'punched_at' => [
                'bail',
                'required',
                'string',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:?\d{2})$/',
                'date',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (CarbonImmutable::parse((string) $value)->greaterThan(CarbonImmutable::now()->addMinutes(self::FUTURE_TOLERANCE_MINUTES))) {
                        $fail('The punch time is in the future.');
                    }
                },
            ],
            'direction' => ['sometimes', 'nullable', Rule::enum(PunchDirection::class)],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:100'],
            'meta' => [
                'sometimes',
                'nullable',
                'array',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_array($value) && strlen((string) json_encode($value)) > self::MAX_META_BYTES) {
                        $fail('The meta object is larger than '.self::MAX_META_BYTES.' bytes.');
                    }
                },
            ],
        ];
    }
}
