<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * Pushed to the Today board over Reverb when attendance of today (or
 * yesterday, for night shifts still running) changed. Carries no personal
 * data: the board refetches what the viewer may see.
 *
 * The channel is named after the workspace subdomain (already public), never
 * the internal tenant id.
 *
 * Queued (ShouldBroadcast), so a WebSocket server that is down never slows
 * or fails the attendance writer.
 */
final class TodayBoardChanged implements ShouldBroadcast
{
    /**
     * @param  list<string>  $dates  Y-m-d
     */
    public function __construct(
        public readonly string $workspace,
        public readonly array $dates,
    ) {}

    public static function channelName(string $workspace): string
    {
        return "workspace.{$workspace}.attendance";
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::channelName($this->workspace))];
    }

    public function broadcastAs(): string
    {
        return 'today.changed';
    }

    /**
     * @return array{dates: list<string>}
     */
    public function broadcastWith(): array
    {
        return ['dates' => $this->dates];
    }
}
