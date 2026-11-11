<?php

declare(strict_types=1);

use App\Modules\Attendance\Events\TodayBoardChanged;
use App\Modules\Attendance\Support\TodayChannel;
use App\Modules\Attendance\Tests\Support\AttendanceTesting;
use App\Modules\Core\Enums\RoleName;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class, AttendanceTesting::class);

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-03-04 12:00:00', 'UTC'));
    $this->attendanceWorkspace();
});

function useReverb(): void
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);
    // Channels are registered on the broadcaster configured at boot (null in tests).
    TodayChannel::register();
}

it('tells Today boards to refresh when today changes, on the workspace channel', function (): void {
    useReverb();
    Event::fake([TodayBoardChanged::class]);

    $this->punch('E-1', '2026-03-04 08:58');

    // Yesterday is re-evaluated with today (night shifts), so both are announced.
    Event::assertDispatchedTimes(TodayBoardChanged::class, 1);
    Event::assertDispatched(TodayBoardChanged::class, function (TodayBoardChanged $e): bool {
        return $e->dates === ['2026-03-03', '2026-03-04']
            && $e->broadcastOn()[0]->name === 'private-workspace.acme.attendance'
            && $e->broadcastAs() === 'today.changed';
    });
});

it('never lets a broken WebSocket setup stop punch processing', function (): void {
    // Broadcasting on, but no keys: creating the broadcaster fails.
    config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => null]);

    $this->punch('E-1', '2026-03-04 08:58');

    expect($this->day('2026-03-04')->first_in_at?->format('H:i'))->toBe('08:58');
});

it('stays quiet for older days and when broadcasting is off', function (): void {
    useReverb();
    Event::fake([TodayBoardChanged::class]);
    $this->punch('E-1', '2026-02-25 09:00', '2026-02-25 17:00');
    Event::assertNotDispatched(TodayBoardChanged::class);

    config(['broadcasting.default' => 'null']);
    $this->punch('E-1', '2026-03-04 09:00');
    Event::assertNotDispatched(TodayBoardChanged::class);
});

it('lets only people who see attendance in this workspace join its channel', function (): void {
    useReverb();
    $hr = $this->createTenantUser($this->tenant, RoleName::HrManager);
    $worker = $this->createTenantUser($this->tenant, RoleName::Employee);
    $channel = 'private-workspace.acme.attendance';
    $auth = $this->tenantUrl($this->tenant, 'broadcasting/auth');

    $this->actingAsTenantUser($hr)->postJson($auth, ['socket_id' => '1234.5678', 'channel_name' => $channel])
        ->assertOk()->assertJsonStructure(['auth']);
    $this->postJson($auth, ['socket_id' => '1234.5678', 'channel_name' => 'private-workspace.globex.attendance'])->assertForbidden();
    $this->actingAsTenantUser($worker)->postJson($auth, ['socket_id' => '1234.5678', 'channel_name' => $channel])->assertForbidden();
});
