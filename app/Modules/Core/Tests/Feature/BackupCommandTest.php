<?php

declare(strict_types=1);

use App\Modules\Core\Enums\RoleName;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/*
 * No RefreshDatabase here: VACUUM INTO cannot run inside the transaction that
 * trait wraps around each test. Each test gets a fresh in-memory central DB.
 */
beforeEach(function (): void {
    Artisan::call('migrate');
});

/**
 * @return list<string>
 */
function backupFiles(): array
{
    return collect(File::allFiles((string) config('ams.backups.path')))
        ->map(fn (SplFileInfo $file) => str_replace('\\', '/', $file->getRelativePathname()))
        ->values()
        ->all();
}

it('backs up central and every tenant with VACUUM INTO', function (): void {
    $acme = $this->createTenant('acme');
    $globex = $this->createTenant('globex');
    $this->createTenantUser($acme, RoleName::Employee, ['email' => 'kept@acme.test']);

    $this->artisan('db:backup')->assertSuccessful();

    $files = backupFiles();
    expect($files)->toHaveCount(3)
        ->and(collect($files)->contains(fn ($f) => str_ends_with($f, '/central.sqlite')))->toBeTrue()
        ->and(collect($files)->contains(fn ($f) => str_ends_with($f, 'tenants/'.$acme->getTenantKey().'.sqlite')))->toBeTrue()
        ->and(collect($files)->contains(fn ($f) => str_ends_with($f, 'tenants/'.$globex->getTenantKey().'.sqlite')))->toBeTrue();

    // The backup is a complete, readable database with the tenant's data.
    $acmeBackup = collect(File::allFiles((string) config('ams.backups.path')))
        ->first(fn (SplFileInfo $f) => $f->getFilename() === $acme->getTenantKey().'.sqlite');
    $pdo = new PDO('sqlite:'.$acmeBackup->getPathname());

    expect((int) $pdo->query("select count(*) from users where email = 'kept@acme.test'")->fetchColumn())->toBe(1)
        ->and($pdo->query('PRAGMA integrity_check')->fetchColumn())->toBe('ok');
    $pdo = null;
});

it('backs up only the requested tenants', function (): void {
    $acme = $this->createTenant('acme');
    $this->createTenant('globex');

    $this->artisan('db:backup', ['--tenant' => [$acme->getTenantKey()]])->assertSuccessful();

    expect(backupFiles())->toHaveCount(1)
        ->and(backupFiles()[0])->toEndWith($acme->getTenantKey().'.sqlite');
});

it('prunes backup folders older than the retention after a successful run, and nothing else', function (): void {
    config(['ams.backups.keep_days' => 14]);
    $root = (string) config('ams.backups.path');
    File::ensureDirectoryExists($root.'/20200101_020000_000');
    File::put($root.'/20200101_020000_000/central.sqlite', 'old');
    File::ensureDirectoryExists($root.'/'.now()->utc()->subDays(3)->format('Ymd_His_v'));
    File::ensureDirectoryExists($root.'/keep-me');

    $this->artisan('db:backup')->assertSuccessful();
    expect(File::isDirectory($root.'/20200101_020000_000'))->toBeTrue();

    $this->artisan('db:backup', ['--prune' => true])->assertSuccessful()->expectsOutputToContain('Pruned 1 backup folder(s)');

    expect(File::isDirectory($root.'/20200101_020000_000'))->toBeFalse()
        ->and(File::isDirectory($root.'/keep-me'))->toBeTrue()
        ->and(collect(File::directories($root))->map(fn ($d) => basename($d))->filter(fn ($n) => $n !== 'keep-me'))->toHaveCount(3);
});
