<?php

declare(strict_types=1);

namespace App\Modules\Core\Tenancy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Stancl\Tenancy\Contracts\TenantDatabaseManager;
use Stancl\Tenancy\Contracts\TenantWithDatabase;

/**
 * Stores each tenant in its own file under `tenancy.database.sqlite_directory`
 * (database/tenants by default, a temp dir in tests).
 *
 * Differs from the package's SQLiteDatabaseManager in three ways that matter here:
 * it never truncates an existing file, it removes the WAL/SHM side files on
 * delete, and it closes the tenant connection first, because Windows cannot
 * unlink a file that still has an open handle.
 */
final class TenantSqliteDatabaseManager implements TenantDatabaseManager
{
    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        $path = $this->path($tenant->database()->getName());

        if (file_exists($path)) {
            return false;
        }

        File::ensureDirectoryExists(dirname($path));

        return touch($path);
    }

    public function deleteDatabase(TenantWithDatabase $tenant): bool
    {
        $path = $this->path($tenant->database()->getName());

        DB::purge('tenant');

        foreach ([$path, $path.'-wal', $path.'-shm'] as $file) {
            if (file_exists($file) && ! unlink($file)) {
                return false;
            }
        }

        return true;
    }

    public function databaseExists(string $name): bool
    {
        return file_exists($this->path($name));
    }

    public function makeConnectionConfig(array $baseConfig, string $databaseName): array
    {
        $baseConfig['database'] = $this->path($databaseName);

        return $baseConfig;
    }

    public function setConnection(string $connection): void
    {
        // SQLite has no server-side admin connection to switch to.
    }

    public function path(string $databaseName): string
    {
        // Names come from tenant UUIDs, but guard anyway: a crafted name must
        // never resolve outside the tenants directory.
        if ($databaseName === '' || basename($databaseName) !== $databaseName) {
            throw new InvalidArgumentException('Invalid tenant database name.');
        }

        return rtrim((string) config('tenancy.database.sqlite_directory'), '/\\').DIRECTORY_SEPARATOR.$databaseName;
    }
}
