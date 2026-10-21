<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Requests;

use App\Modules\Core\Services\SettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;

/**
 * Only the envelope is validated here; each item is validated on its own
 * (items()), so one bad punch does not reject the rest of the batch.
 */
class StorePunchBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $max = (int) app(SettingsService::class)->get('attendance.max_batch_size');

        return [
            'punches' => ['required', 'array', 'list', 'min:1', "max:{$max}"],
        ];
    }

    /**
     * @return list<array{index: int, data: array<string, mixed>|null, errors: array<string, list<string>>|null}>
     */
    public function items(): array
    {
        $items = [];

        foreach ((array) $this->validated('punches') as $index => $punch) {
            $validator = Validator::make(is_array($punch) ? $punch : [], PunchPayload::rules());

            $items[] = $validator->fails()
                ? ['index' => (int) $index, 'data' => null, 'errors' => $validator->errors()->toArray()]
                : ['index' => (int) $index, 'data' => $validator->validated(), 'errors' => null];
        }

        return $items;
    }
}
