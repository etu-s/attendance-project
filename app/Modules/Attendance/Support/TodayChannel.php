<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Support;

use App\Modules\Attendance\Events\TodayBoardChanged;
use App\Modules\Central\Models\Tenant;
use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\Broadcast;

/**
 * The live Today board's private channel: a workspace's own channel, for
 * people who may see attendance there. Laravel registers channels on the
 * broadcaster configured at boot; call register() again after switching
 * broadcasters (tests).
 */
final class TodayChannel
{
    public static function register(): void
    {
        Broadcast::channel(
            TodayBoardChanged::channelName('{workspace}'),
            fn (User $user, string $workspace): bool => self::currentWorkspace() === $workspace && $user->can('attendance.view'),
        );
    }

    /**
     * The current workspace's subdomain, or null outside tenant context.
     */
    public static function currentWorkspace(): ?string
    {
        $tenant = tenant();

        return $tenant instanceof Tenant ? $tenant->subdomain() : null;
    }
}
