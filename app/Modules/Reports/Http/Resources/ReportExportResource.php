<?php

declare(strict_types=1);

namespace App\Modules\Reports\Http\Resources;

use App\Modules\Reports\Models\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReportExport
 */
class ReportExportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'report' => $this->report->value,
            'report_label' => $this->report->label(),
            'format' => $this->format->value,
            'params' => $this->params,
            'status' => $this->status->value,
            'file_name' => $this->file_name,
            'row_count' => $this->row_count,
            'size_bytes' => $this->size_bytes,
            'error' => $this->error,
            'downloadable' => $this->isDownloadable() && ($this->expires_at === null || $this->expires_at->isFuture()),
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
        ];
    }
}
