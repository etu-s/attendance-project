<?php

declare(strict_types=1);

namespace App\Modules\Core\Settings;

use InvalidArgumentException;

final class SettingsRegistry
{
    /** @var array<string, SettingDefinition> */
    private array $definitions = [];

    public function register(SettingDefinition ...$definitions): void
    {
        foreach ($definitions as $definition) {
            if (isset($this->definitions[$definition->key])) {
                throw new InvalidArgumentException("Setting [{$definition->key}] is already registered.");
            }

            $this->definitions[$definition->key] = $definition;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    public function get(string $key): SettingDefinition
    {
        return $this->definitions[$key] ?? throw new InvalidArgumentException("Unknown setting [{$key}].");
    }

    /**
     * @return array<string, SettingDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->definitions);
    }
}
