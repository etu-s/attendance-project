<?php

declare(strict_types=1);

namespace App\Modules\Organization\Http\Resources;

use App\Modules\Organization\Models\EmployeeImport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmployeeImport
 */
class EmployeeImportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'original_name' => $this->original_name,
            'total_rows' => $this->total_rows,
            'created_count' => $this->created_count,
            'failed_count' => $this->failed_count,
            'errors' => $this->errors ?? [],
            'started_at' => $this->started_at?->toIso8601ZuluString(),
            'finished_at' => $this->finished_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
