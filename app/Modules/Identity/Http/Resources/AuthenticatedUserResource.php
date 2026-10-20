<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Core\Services\SettingsService;
use App\Modules\Identity\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Session bootstrap for the SPA: who am I, what may I do, and how should
 * dates be shown in this workspace.
 *
 * @mixin User
 */
class AuthenticatedUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $settings = app(SettingsService::class);

        return [
            'user' => new UserResource($this->resource),
            'permissions' => $this->getAllPermissions()->pluck('name')->sort()->values()->all(),
            'workspace' => [
                'name' => (string) tenant('name'),
                'timezone' => $settings->get('general.timezone'),
                'date_format' => $settings->get('general.date_format'),
                'time_format' => $settings->get('general.time_format'),
                'week_start' => $settings->get('general.week_start'),
            ],
        ];
    }
}
