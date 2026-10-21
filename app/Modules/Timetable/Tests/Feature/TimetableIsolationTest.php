<?php

declare(strict_types=1);

use App\Modules\Core\Tenancy\TenantSqliteDatabaseManager;
use App\Modules\Timetable\Models\Shift;
use App\Modules\Timetable\Models\Timetable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->acme = $this->createTenant('acme');
    $this->globex = $this->createTenant('globex');

    [$this->globexTimetable, $this->globexShift] = $this->globex->run(function (): array {
        $timetable = Timetable::factory()->create(['name' => 'Globex Night', 'code' => 'GLX-NIGHT']);

        return [$timetable, Shift::factory()->on($timetable, [0])->create(['name' => 'Globex Rota', 'code' => 'GLX-ROTA'])];
    });
});

it('keeps tenant B\'s timetables and shifts out of tenant A\'s file', function (): void {
    $pdo = new PDO('sqlite:'.app(TenantSqliteDatabaseManager::class)->path($this->acme->database()->getName()));

    foreach ([
        "select count(*) from timetables where code like 'GLX%'",
        "select count(*) from shifts where code like 'GLX%'",
        'select count(*) from shift_days',
        'select count(*) from timetable_breaks',
        "select count(*) from audit_logs where after like '%GLX-%'",
    ] as $sql) {
        expect((int) $pdo->query($sql)->fetchColumn())->toBe(0, $sql);
    }

    $pdo = null;
});

it('does not list or resolve another tenant\'s timetables and shifts', function (): void {
    $this->actingAsTenantUser($this->tenantAdmin($this->acme));

    $this->getJson($this->tenantUrl($this->acme, 'timetables'))->assertJsonPath('meta.total', 0);
    $this->getJson($this->tenantUrl($this->acme, 'shifts'))->assertJsonPath('meta.total', 0);
    $this->getJson($this->tenantUrl($this->acme, "timetables/{$this->globexTimetable->id}"))->assertNotFound();
    $this->getJson($this->tenantUrl($this->acme, "shifts/{$this->globexShift->id}/preview?from=2026-01-01&to=2026-01-02"))->assertNotFound();
});
