<?php

declare(strict_types=1);

use App\Modules\Attendance\Enums\AttendanceStatus;
use App\Modules\Attendance\Models\OrphanPunch;
use App\Modules\Attendance\Models\PunchMatch;
use App\Modules\Attendance\Models\PunchSource;
use App\Modules\Attendance\Models\RawPunch;
use App\Modules\Attendance\Services\PunchSourceService;
use App\Modules\Attendance\Tests\Support\AttendanceTesting;
use App\Modules\Core\Services\SettingsService;
use App\Modules\Core\Tenancy\TenantSqliteDatabaseManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class, AttendanceTesting::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-04 12:00:00', 'UTC'));
    $this->attendanceWorkspace();
    [$this->source, $this->key] = $this->tenant->run(fn () => app(PunchSourceService::class)->create(['name' => 'Front door', 'kind' => 'device']));
});

it('stores a punch, answers 202 and processes it on the queue', function (): void {
    $response = $this->postPunch(['employee_identifier' => 'E-1', 'punched_at' => '2026-03-02T08:55:00Z', 'direction' => 'in', 'meta' => ['door' => 'A']])
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'accepted');

    $this->tenant->run(function () use ($response): void {
        $raw = RawPunch::query()->findOrFail($response->json('data.id'));
        expect($raw->punched_at->format('Y-m-d H:i:s'))->toBe('2026-03-02 08:55:00')
            ->and($raw->meta)->toBe(['door' => 'A'])
            ->and(PunchMatch::query()->whereKey($raw->id)->value('employee_id'))->toBe($this->employee->id);
    });

    expect($this->day('2026-03-02'))->status->toBe(AttendanceStatus::Incomplete)
        ->and($this->tenant->run(fn () => PunchSource::query()->find($this->source->id)->last_used_at))->not->toBeNull();
});

it('converts offsets to UTC', function (): void {
    $this->postPunch(['employee_identifier' => 'E-1', 'punched_at' => '2026-03-02T13:55:00+05:00'])->assertStatus(202);

    expect($this->tenant->run(fn () => RawPunch::query()->value('punched_at'))->format('H:i'))->toBe('08:55');
});

it('reports duplicates by instant (case-insensitive identifier) and by idempotency key', function (): void {
    $first = $this->postPunch(['employee_identifier' => 'E-1', 'punched_at' => '2026-03-02T08:55:00Z', 'idempotency_key' => 'k-1'])->json('data.id');

    $this->postPunch(['employee_identifier' => 'e-1', 'punched_at' => '2026-03-02T08:55:00Z'])
        ->assertStatus(202)->assertJsonPath('data', ['status' => 'duplicate', 'id' => $first]);
    $this->postPunch(['employee_identifier' => 'E-1', 'punched_at' => '2026-03-02T09:30:00Z', 'idempotency_key' => 'k-1'])
        ->assertStatus(202)->assertJsonPath('data', ['status' => 'duplicate', 'id' => $first]);

    expect($this->tenant->run(fn () => RawPunch::query()->count()))->toBe(1);
});

it('validates the payload strictly', function (array $body, string $field): void {
    $this->postPunch($body)->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'no offset' => [['employee_identifier' => 'E-1', 'punched_at' => '2026-03-02T08:55:00'], 'punched_at'],
    'not a date' => [['employee_identifier' => 'E-1', 'punched_at' => '2026-13-45T08:55:00Z'], 'punched_at'],
    'future' => [['employee_identifier' => 'E-1', 'punched_at' => '2026-03-04T12:30:00Z'], 'punched_at'],
    'direction' => [['employee_identifier' => 'E-1', 'punched_at' => '2026-03-02T08:55:00Z', 'direction' => 'sideways'], 'direction'],
    'identifier' => [['punched_at' => '2026-03-02T08:55:00Z'], 'employee_identifier'],
    'meta size' => [['employee_identifier' => 'E-1', 'punched_at' => '2026-03-02T08:55:00Z', 'meta' => ['x' => str_repeat('a', 3000)]], 'meta'],
]);

it('keeps punches of unknown identifiers as open orphans', function (): void {
    $this->postPunch(['employee_identifier' => 'BADGE-9', 'punched_at' => '2026-03-02T08:55:00Z'])->assertStatus(202);

    $this->tenant->run(function (): void {
        expect(OrphanPunch::query()->open()->where('employee_identifier', 'BADGE-9')->count())->toBe(1)
            ->and(PunchMatch::query()->count())->toBe(0);
    });
});

describe('source authentication', function (): void {
    it('refuses missing, malformed and wrong keys', function (): void {
        $body = ['employee_identifier' => 'E-1', 'punched_at' => '2026-03-02T08:55:00Z'];

        $this->postJson($this->tenantUrl($this->tenant, 'punches'), $body)->assertUnauthorized();
        $this->postPunch($body, 'nonsense')->assertUnauthorized();
        $this->postPunch($body, 'ams_'.$this->source->key_prefix.'_'.str_repeat('x', 40))->assertUnauthorized();

        expect($this->tenant->run(fn () => RawPunch::query()->count()))->toBe(0);
    });

    it('refuses inactive sources and addresses outside the allowlist', function (): void {
        $body = ['employee_identifier' => 'E-1', 'punched_at' => '2026-03-02T08:55:00Z'];

        $this->tenant->run(fn () => $this->source->update(['ip_allowlist' => ['10.0.0.0/8']]));
        $this->postPunch($body)->assertForbidden();

        $this->tenant->run(fn () => $this->source->update(['ip_allowlist' => ['127.0.0.1']]));
        $this->postPunch($body)->assertStatus(202);

        $this->tenant->run(fn () => $this->source->update(['is_active' => false]));
        $this->postPunch($body)->assertForbidden();
    });

    it('requires a fresh, valid HMAC signature when the source signs', function (): void {
        [, $key, $secret] = $this->tenant->run(fn () => app(PunchSourceService::class)->create(['name' => 'Signed', 'kind' => 'integration', 'signed' => true]));
        $body = ['employee_identifier' => 'E-1', 'punched_at' => '2026-03-02T08:55:00Z'];
        $sign = fn (int $ts, array $payload) => ['X-Timestamp' => (string) $ts, 'X-Signature' => 'sha256='.hash_hmac('sha256', $ts.'.'.json_encode($payload), $secret)];
        $now = CarbonImmutable::now()->getTimestamp();

        $this->postPunch($body, $key)->assertUnauthorized();
        $this->postPunch($body, $key, headers: $sign($now - 600, $body))->assertUnauthorized();
        $this->postPunch($body, $key, headers: $sign($now, ['tampered' => true]))->assertUnauthorized();
        $this->postPunch($body, $key, headers: $sign($now, $body))->assertStatus(202);
    });

    it('limits the request rate per source', function (): void {
        config(['ams.rate_limits.punches_per_minute' => 2]);
        $body = fn (int $m) => ['employee_identifier' => 'E-1', 'punched_at' => "2026-03-02T08:5{$m}:00Z"];

        $this->postPunch($body(1))->assertStatus(202);
        $this->postPunch($body(2))->assertStatus(202);
        $this->postPunch($body(3))->assertStatus(429);
    });
});

describe('batch', function (): void {
    it('accepts the valid items and reports each one', function (): void {
        $response = $this->postPunch(['punches' => [
            ['employee_identifier' => 'E-1', 'punched_at' => '2026-03-02T08:55:00Z'],
            ['employee_identifier' => 'E-1', 'punched_at' => 'yesterday'],
            ['employee_identifier' => 'E-1', 'punched_at' => '2026-03-02T08:55:00Z'],
            ['employee_identifier' => 'E-1', 'punched_at' => '2026-03-02T17:01:00Z'],
        ]], path: 'punches/batch')->assertStatus(202);

        expect(array_column($response->json('data'), 'status'))->toBe(['accepted', 'invalid', 'duplicate', 'accepted'])
            ->and($response->json('data.1.errors'))->toHaveKey('punched_at')
            ->and($response->json('data.2.id'))->toBe($response->json('data.0.id'))
            ->and($response->json('meta'))->toBe(['accepted' => 2, 'duplicate' => 1, 'invalid' => 1])
            ->and($this->day('2026-03-02')->status)->toBe(AttendanceStatus::Present);
    });

    it('refuses an empty or oversized batch', function (): void {
        $this->tenant->run(fn () => app(SettingsService::class)->update(['attendance.max_batch_size' => 2]));
        $item = ['employee_identifier' => 'E-1', 'punched_at' => '2026-03-02T08:55:00Z'];

        $this->postPunch(['punches' => []], path: 'punches/batch')->assertUnprocessable();
        $this->postPunch(['punches' => [$item, $item, $item]], path: 'punches/batch')->assertUnprocessable()->assertJsonValidationErrors('punches');
    });
});

it('never updates or deletes raw punches', function (): void {
    $this->postPunch(['employee_identifier' => 'E-1', 'punched_at' => '2026-03-02T08:55:00Z'])->assertStatus(202);

    $this->tenant->run(function (): void {
        expect(fn () => RawPunch::query()->firstOrFail()->update(['employee_identifier' => 'X']))->toThrow(LogicException::class)
            ->and(fn () => DB::table('raw_punches')->update(['employee_identifier' => 'X']))->toThrow(QueryException::class)
            ->and(fn () => DB::table('raw_punches')->delete())->toThrow(QueryException::class)
            ->and(RawPunch::query()->value('employee_identifier'))->toBe('E-1');
    });
});

it('keeps each tenant\'s keys and punches to itself', function (): void {
    $acme = $this->tenant;
    $this->attendanceWorkspace(subdomain: 'globex');
    $globex = $this->tenant;
    [, $globexKey] = $globex->run(fn () => app(PunchSourceService::class)->create(['name' => 'Globex door', 'kind' => 'device']));

    $this->postPunch(['employee_identifier' => 'E-1', 'punched_at' => '2026-03-02T08:55:00Z'], $globexKey, tenant: $acme)->assertUnauthorized();
    $this->postPunch(['employee_identifier' => 'GLOBEX-ONLY', 'punched_at' => '2026-03-02T08:55:00Z'], $globexKey, tenant: $globex)->assertStatus(202);

    $pdo = new PDO('sqlite:'.app(TenantSqliteDatabaseManager::class)->path($acme->database()->getName()));
    expect((int) $pdo->query("select count(*) from raw_punches where employee_identifier = 'GLOBEX-ONLY'")->fetchColumn())->toBe(0)
        ->and((int) $pdo->query('select count(*) from punch_sources')->fetchColumn())->toBe(1);
    $pdo = null;
});
