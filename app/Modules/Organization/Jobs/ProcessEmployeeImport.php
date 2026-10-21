<?php

declare(strict_types=1);

namespace App\Modules\Organization\Jobs;

use App\Modules\Organization\Models\EmployeeImport;
use App\Modules\Organization\Services\EmployeeImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs in the tenant's context: stancl's QueueTenancyBootstrapper records the
 * tenant id in the payload and re-initializes tenancy in the worker. Only the
 * import id is serialized (no personal data in the queue payload). Re-delivery
 * is harmless: EmployeeImportService::process() only runs pending imports.
 */
final class ProcessEmployeeImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Partial imports are not safe to retry blindly; failures are recorded on the import. */
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly int $importId,
    ) {}

    public function handle(EmployeeImportService $imports): void
    {
        $import = EmployeeImport::query()->find($this->importId);

        if ($import !== null) {
            $imports->process($import);
        }
    }
}
