<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

enum SettingType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Boolean = 'boolean';

    /**
     * Validation rules that guarantee the stored value matches the type.
     *
     * @return list<string>
     */
    public function rules(): array
    {
        return match ($this) {
            self::String => ['string', 'max:255'],
            self::Integer => ['integer'],
            self::Boolean => ['boolean'],
        };
    }

    public function cast(mixed $value): string|int|bool|null
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::String => (string) $value,
            self::Integer => (int) $value,
            self::Boolean => filter_var($value, FILTER_VALIDATE_BOOLEAN),
        };
    }
}
