<?php

declare(strict_types=1);

namespace App\Modules\Core\Console;

use App\Modules\Core\Services\DatabaseBackupService;
use Illuminate\Console\Command;
use Stancl\Tenancy\Contracts\Tenant;
use Throwable;

final class BackupDatabasesCommand extends Command
{
    protected $signature = 'db:backup
        {--tenant=* : Only back up these tenant ids (central is skipped)}
        {--skip-central : Do not back up the central database}';

    protected $description = 'Back up the central and tenant SQLite databases with VACUUM INTO';

    public function handle(DatabaseBackupService $backups): int
    {
        $directory = $backups->newBackupDirectory();
        $only = array_values(array_filter((array) $this->option('tenant')));
        $failures = 0;

        if ($only === [] && ! $this->option('skip-central')) {
            $backups->backupCentral($directory);
            $this->components->task('central');
        }

        $tenants = tenancy()->query()
            ->when($only !== [], fn ($query) => $query->whereIn('id', $only))
            ->orderBy('id')
            ->get();

        foreach ($tenants as $tenant) {
            /** @var Tenant $tenant */
            try {
                $backups->backupTenant($tenant, $directory);
                $this->components->task('tenant '.$tenant->getTenantKey());
            } catch (Throwable $e) {
                // Keep going: one unreadable tenant must not block everyone else's backup.
                report($e);
                $failures++;
                $this->components->error('tenant '.$tenant->getTenantKey().' failed: '.$e->getMessage());
            }
        }

        $this->components->info("Backups written to {$directory}");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
