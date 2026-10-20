<?php

declare(strict_types=1);

namespace App\Modules\Core\Settings;

use App\Modules\Core\Enums\SettingType;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Declares one tenant setting. Modules register their definitions with the
 * SettingsRegistry; the default is copied into each tenant's settings table
 * at provisioning, after which the tenant's stored value is authoritative.
 */
final readonly class SettingDefinition
{
    /**
     * @param  list<string|int>|null  $options  allowed values (rendered as a select in the UI)
     * @param  list<mixed>  $rules  extra validation rules on top of the type rules
     */
    public function __construct(
        public string $key,
        public SettingType $type,
        public string|int|bool $default,
        public string $label,
        public ?string $description = null,
        public ?array $options = null,
        public array $rules = [],
    ) {
        if (! preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_.]*$/', $key)) {
            throw new InvalidArgumentException("Setting key [{$key}] must look like `group.name`.");
        }
    }

    public function group(): string
    {
        return explode('.', $this->key, 2)[0];
    }

    /**
     * @return list<mixed>
     */
    public function validationRules(): array
    {
        $rules = ['required', ...$this->type->rules(), ...$this->rules];

        if ($this->options !== null) {
            $rules[] = Rule::in($this->options);
        }

        return $rules;
    }
}
