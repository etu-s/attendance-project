<?php

declare(strict_types=1);

namespace App\Modules\Reports\Jobs;

use App\Modules\Reports\Models\ReportExport;
use App\Modules\Reports\Services\ReportExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Builds one report file. Reads only (plus its own status row), so it does
 * not take the attendance writer lock. Not retried: a failed export is shown
 * as failed and the user requests a new one.
 */
final class GenerateReportExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly int $exportId,
    ) {}

    public function handle(ReportExportService $exports): void
    {
        $export = ReportExport::query()->find($this->exportId);

        if ($export !== null) {
            $exports->generate($export);
        }
    }

    /**
     * Timeouts and crashes outside generate()'s own error handling.
     */
    public function failed(?Throwable $e): void
    {
        $export = ReportExport::query()->find($this->exportId);

        if ($export !== null && ! $export->isDownloadable()) {
            app(ReportExportService::class)->fail($export, 'The export could not be generated.');
        }
    }
}
