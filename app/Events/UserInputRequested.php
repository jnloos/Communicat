<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserInputRequested implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $projectId,
        public readonly string $reason = 'user_addressed',
        // The specific user the expert handed off to. Null = unresolved
        // (e.g. no candidates) → every client falls back to prompting.
        public readonly ?int $targetUserId = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("projects.{$this->projectId}")];
    }

    public function broadcastAs(): string
    {
        return 'UserInputRequested';
    }

    public function broadcastWith(): array
    {
        return ['reason' => $this->reason, 'targetUserId' => $this->targetUserId];
    }
}
