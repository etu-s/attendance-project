<?php

declare(strict_types=1);

namespace App\Modules\Reports\Database\Factories;

use App\Modules\Reports\Enums\ExportFormat;
use App\Modules\Reports\Enums\ExportStatus;
use App\Modules\Reports\Enums\ReportKind;
use App\Modules\Reports\Models\ReportExport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Tenant context only; set requested_by. Default: a queued CSV of the
 * attendance summary for March 2026.
 *
 * @extends Factory<ReportExport>
 */
class ReportExportFactory extends Factory
{
    protected $model = ReportExport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'report' => ReportKind::AttendanceSummary,
            'format' => ExportFormat::Csv,
            'params' => ['from' => '2026-03-01', 'to' => '2026-03-31'],
            'status' => ExportStatus::Queued,
            'file_name' => 'attendance-summary-2026-03-01-2026-03-31.csv',
        ];
    }
}
