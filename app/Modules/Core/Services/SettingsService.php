<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use App\Modules\Core\Models\Setting;
use App\Modules\Core\Settings\SettingDefinition;
use App\Modules\Core\Settings\SettingsRegistry;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;

final class SettingsService
{
    public function __construct(
        private readonly SettingsRegistry $registry,
    ) {}

    public function get(string $key): string|int|bool|null
    {
        $this->registry->get($key);

        return $this->all()[$key];
    }

    /**
     * Every registered setting with the tenant's stored value, falling back to
     * the definition default for settings added after the tenant was created.
     *
     * @return array<string, string|int|bool|null>
     */
    public function all(): array
    {
        return Cache::remember($this->cacheKey(), now()->addHour(), function (): array {
            $stored = Setting::query()->pluck('value', 'key');
            $values = [];

            foreach ($this->registry->all() as $key => $definition) {
                $values[$key] = $stored->has($key)
                    ? $definition->type->cast(json_decode((string) $stored[$key], true))
                    : $definition->default;
            }

            return $values;
        });
    }

    /**
     * Values must already be validated against the definitions (UpdateSettingsRequest).
     *
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): void
    {
        DB::transaction(function () use ($values): void {
            foreach ($values as $key => $value) {
                $definition = $this->registry->get($key);

                Setting::query()->updateOrCreate(
                    ['key' => $key],
                    ['value' => $this->encode($definition, $value)],
                );
            }
        });

        $this->flush();
    }

    /**
     * Inserts any registered setting the tenant does not have yet. Existing
     * values are never overwritten, so this is safe to run on every deploy.
     *
     * @param  array<string, mixed>  $overrides  initial values to use instead of the defaults
     */
    public function seedDefaults(array $overrides = []): void
    {
        DB::transaction(function () use ($overrides): void {
            foreach ($this->registry->all() as $key => $definition) {
                Setting::query()->firstOrCreate(
                    ['key' => $key],
                    ['value' => $this->encode($definition, $overrides[$key] ?? $definition->default)],
                );
            }
        });

        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget($this->cacheKey());
    }

    private function encode(SettingDefinition $definition, mixed $value): string
    {
        return (string) json_encode($definition->type->cast($value), JSON_THROW_ON_ERROR);
    }

    /**
     * The tenant id is part of the key on top of the tenancy cache tags, so a
     * misconfigured (untagged) cache store still cannot serve another tenant's settings.
     */
    private function cacheKey(): string
    {
        if (! tenancy()->initialized) {
            throw new LogicException('Settings are tenant-scoped and require tenant context.');
        }

        return 'tenant:'.tenant()->getTenantKey().':settings';
    }
}
