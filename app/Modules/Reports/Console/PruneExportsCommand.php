<?php

declare(strict_types=1);

namespace App\Modules\Reports\Console;

use App\Modules\Reports\Services\ReportExportService;
use Illuminate\Console\Command;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Scheduled daily. Deletes the files of expired report exports in every
 * tenant (suspended ones too: their files expire all the same).
 */
final class PruneExportsCommand extends Command
{
    protected $signature = 'reports:prune-exports {--tenant=* : Only these tenant ids}';

    protected $description = 'Delete expired report export files';

    public function handle(): int
    {
        $only = array_values(array_filter((array) $this->option('tenant')));
        $tenants = tenancy()->query()
            ->when($only !== [], fn ($query) => $query->whereIn('id', $only))
            ->orderBy('id')
            ->get();

        $pruned = 0;
        foreach ($tenants as $tenant) {
            /** @var Tenant $tenant */
            $pruned += (int) $tenant->run(fn (): int => app(ReportExportService::class)->pruneExpired());
        }

        $this->components->info("Pruned {$pruned} export(s).");

        return self::SUCCESS;
    }
}
