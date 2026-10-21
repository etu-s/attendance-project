<?php

declare(strict_types=1);

namespace App\Modules\Scheduling\Http\Resources;

use App\Modules\Scheduling\Models\Holiday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * `occurs_on` is the date in the requested year (lists only).
 *
 * @mixin Holiday
 */
class HolidayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'occurs_on' => $this->resource->getAttribute('occurs_on') ?? $this->date->toDateString(),
            'name' => $this->name,
            'recurs_yearly' => $this->recurs_yearly,
            'location' => $this->location ? ['id' => $this->location->id, 'name' => $this->location->name] : null,
        ];
    }
}
