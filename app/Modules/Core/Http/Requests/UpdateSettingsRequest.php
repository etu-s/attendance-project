<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Settings\SettingsRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Body: { "settings": [ { "key": "general.timezone", "value": "Asia/Karachi" }, ... ] }
 *
 * A list of key/value pairs rather than an object, because setting keys contain
 * dots, which Laravel validation would otherwise treat as nesting.
 */
class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.update') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'settings' => ['required', 'array', 'min:1'],
            'settings.*' => ['required', 'array:key,value'],
            'settings.*.key' => ['required', 'string', 'distinct', Rule::in(app(SettingsRegistry::class)->keys())],
            'settings.*.value' => ['present'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (ValidatorInstance $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $registry = app(SettingsRegistry::class);

                foreach ((array) $this->input('settings') as $index => $item) {
                    $definition = $registry->get((string) $item['key']);
                    $check = Validator::make(
                        ['value' => $item['value']],
                        ['value' => $definition->validationRules()],
                        [],
                        ['value' => $definition->label],
                    );

                    foreach ($check->errors()->get('value') as $message) {
                        $validator->errors()->add("settings.{$index}.value", $message);
                    }
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $values = [];

        foreach ((array) $this->validated('settings') as $item) {
            $values[(string) $item['key']] = $item['value'];
        }

        return $values;
    }
}
