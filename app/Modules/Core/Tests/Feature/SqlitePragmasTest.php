<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function pragmas(string $connection): array
{
    $db = DB::connection($connection);

    return [
        'journal_mode' => strtolower((string) $db->selectOne('PRAGMA journal_mode')->journal_mode),
        'foreign_keys' => (int) $db->selectOne('PRAGMA foreign_keys')->foreign_keys,
        'busy_timeout' => (int) $db->selectOne('PRAGMA busy_timeout')->timeout,
        'synchronous' => (int) $db->selectOne('PRAGMA synchronous')->synchronous,
    ];
}

it('applies WAL, foreign keys, busy timeout and NORMAL sync to a file-based central connection', function (): void {
    // Tests run central in memory (where WAL does not apply), so check the
    // real central configuration against a temporary file.
    $file = storage_path('framework/testing/central-pragma-'.getmypid().'.sqlite');
    File::put($file, '');
    config(['database.connections.central_file' => array_merge(config('database.connections.central'), ['database' => $file])]);

    try {
        expect(pragmas('central_file'))->toBe([
            'journal_mode' => 'wal',
            'foreign_keys' => 1,
            'busy_timeout' => 5000,
            'synchronous' => 1, // NORMAL
        ]);
    } finally {
        DB::purge('central_file');
        gc_collect_cycles();
        foreach ([$file, $file.'-wal', $file.'-shm'] as $path) {
            File::delete($path);
        }
    }
});

it('applies the same pragmas to tenant connections', function (): void {
    $tenant = $this->createTenant('acme');

    $tenant->run(function (): void {
        expect(pragmas('tenant'))->toBe([
            'journal_mode' => 'wal',
            'foreign_keys' => 1,
            'busy_timeout' => 5000,
            'synchronous' => 1,
        ]);
    });
});

it('enforces foreign keys on the in-memory central test connection too', function (): void {
    expect(pragmas('central')['foreign_keys'])->toBe(1);
});
