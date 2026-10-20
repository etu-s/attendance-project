<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Resources;

use App\Modules\Core\Settings\SettingDefinition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Wraps a definition plus the tenant's current value: ['definition' => ..., 'value' => ...].
 *
 * @property array{definition: SettingDefinition, value: string|int|bool|null} $resource
 */
class SettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $definition = $this->resource['definition'];

        return [
            'key' => $definition->key,
            'group' => $definition->group(),
            'type' => $definition->type->value,
            'label' => $definition->label,
            'description' => $definition->description,
            'options' => $definition->options,
            'default' => $definition->default,
            'value' => $this->resource['value'],
        ];
    }
}
