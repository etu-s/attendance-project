<?php

declare(strict_types=1);

namespace App\Modules\Central\Http\Resources;

use App\Modules\Central\Models\SuperAdmin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SuperAdmin
 */
class SuperAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'role' => 'super-admin',
            'last_login_at' => $this->last_login_at?->toIso8601ZuluString(),
        ];
    }
}
