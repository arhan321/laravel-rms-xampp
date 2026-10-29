<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Invalidation signal only. The browser fetches current authorized read-only
 * state from Laravel: no raw controller data or secrets on a public channel.
 */
final class RmsRobotChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    public function __construct(public readonly int $robotId, public readonly string $kind)
    {
    }

    public function broadcastOn(): array
    {
        return [new Channel('rms.fleet')];
    }

    public function broadcastAs(): string
    {
        return 'rms.robot.changed';
    }

    public function broadcastWith(): array
    {
        return ['robot_id' => $this->robotId, 'kind' => $this->kind];
    }
}
