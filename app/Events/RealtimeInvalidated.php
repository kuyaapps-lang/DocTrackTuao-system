<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Broadcasts an authorization-safe instruction to re-read an API resource. */
class RealtimeInvalidated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly array $channels,
        public readonly string $type,
        public readonly ?int $resourceId = null,
        public readonly array $areas = [],
    ) {}

    public function broadcastOn(): array
    {
        return array_map(fn (string $channel) => new PrivateChannel($channel), $this->channels);
    }

    public function broadcastAs(): string
    {
        return 'doc-track.invalidated';
    }

    public function broadcastWith(): array
    {
        return [
            'type' => $this->type,
            'resource_id' => $this->resourceId,
            'areas' => array_values($this->areas),
            'occurred_at' => now()->toIso8601String(),
        ];
    }
}
