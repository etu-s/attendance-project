<?php

declare(strict_types=1);

use App\Modules\Core\Enums\SettingType;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Core\Settings\SettingsRegistry;
use Illuminate\Support\Facades\Validator;

it('requires group.name keys', function (): void {
    expect(fn () => new SettingDefinition('timezone', SettingType::String, 'UTC', 'Timezone'))
        ->toThrow(InvalidArgumentException::class);

    expect((new SettingDefinition('general.timezone', SettingType::String, 'UTC', 'Timezone'))->group())->toBe('general');
});

it('refuses to register the same key twice', function (): void {
    $registry = new SettingsRegistry;
    $definition = new SettingDefinition('general.a', SettingType::String, 'x', 'A');
    $registry->register($definition);

    expect(fn () => $registry->register($definition))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $registry->get('general.missing'))->toThrow(InvalidArgumentException::class);
});

it('builds validation rules from type, options and extra rules', function (): void {
    $definition = new SettingDefinition('general.week_start', SettingType::Integer, 1, 'Week start', options: [0, 1]);

    expect(Validator::make(['v' => 1], ['v' => $definition->validationRules()])->passes())->toBeTrue()
        ->and(Validator::make(['v' => 5], ['v' => $definition->validationRules()])->passes())->toBeFalse()
        ->and(Validator::make(['v' => 'x'], ['v' => $definition->validationRules()])->passes())->toBeFalse()
        ->and(Validator::make([], ['v' => $definition->validationRules()])->passes())->toBeFalse();
});

it('casts stored values back to their declared type', function (): void {
    expect(SettingType::Integer->cast('7'))->toBe(7)
        ->and(SettingType::Boolean->cast('false'))->toBeFalse()
        ->and(SettingType::Boolean->cast(1))->toBeTrue()
        ->and(SettingType::String->cast(12))->toBe('12')
        ->and(SettingType::String->cast(null))->toBeNull();
});
