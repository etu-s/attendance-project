<?php

declare(strict_types=1);

namespace App\Modules\Core\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Consistent online backups via `VACUUM INTO`, which SQLite runs as a read
 * transaction: safe while the app is writing and never a torn copy of a
 * live file (copying a WAL-mode file directly can miss committed pages).
 */
final class DatabaseBackupService
{
    /**
     * @return string the new backup directory, e.g. storage/backups/20261020_120000
     */
    public function newBackupDirectory(): string
    {
        $directory = rtrim((string) config('ams.backups.path'), '/\\')
            .DIRECTORY_SEPARATOR.now()->utc()->format('Ymd_His_v');

        File::ensureDirectoryExists($directory);

        return $directory;
    }

    public function backupCentral(string $directory): string
    {
        $target = $directory.DIRECTORY_SEPARATOR.'central.sqlite';
        $this->vacuumInto(DB::connection((string) config('tenancy.database.central_connection')), $target);

        return $target;
    }

    public function backupTenant(Tenant $tenant, string $directory): string
    {
        // Resolve the target before entering tenant context: the filesystem
        // bootstrapper rewrites storage paths per tenant.
        $target = $directory.DIRECTORY_SEPARATOR.'tenants'.DIRECTORY_SEPARATOR.$tenant->getTenantKey().'.sqlite';
        File::ensureDirectoryExists(dirname($target));

        $tenant->run(fn () => $this->vacuumInto(DB::connection('tenant'), $target));

        return $target;
    }

    private function vacuumInto(ConnectionInterface $connection, string $target): void
    {
        if (file_exists($target)) {
            throw new RuntimeException('Backup target already exists.');
        }

        if ($connection->transactionLevel() > 0) {
            throw new RuntimeException('VACUUM INTO cannot run inside a transaction.');
        }

        $connection->statement('VACUUM INTO '.$connection->getPdo()->quote($target));
    }
}
