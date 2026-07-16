<?php

namespace App\Events;

use App\Models\Expert;
use App\Models\Message;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageGenerated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $projectId,
        public readonly ?int $messageId = null,
        public readonly int $nextTurnDelaySeconds = 0,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("projects.{$this->projectId}")];
    }

    public function broadcastAs(): string
    {
        return 'MessageGenerated';
    }

    public function broadcastWith(): array
    {
        $message = $this->messageId ? Message::find($this->messageId) : null;

        // The single polymorphic adjacency_partner fans back out into the two
        // payload keys the frontend already listens for (kept stable on purpose).
        $partnerType = $message?->adjacency_partner_type;
        $partnerId = $message?->adjacency_partner_id;

        return [
            'project_id' => $this->projectId,
            'message_id' => $this->messageId,
            'expert_id' => $message?->expert_id,
            'addressed_expert_id' => $partnerType === Expert::class ? $partnerId : null,
            'addressed_user_id' => $partnerType === User::class ? $partnerId : null,
            'next_turn_delay_seconds' => $this->nextTurnDelaySeconds,
        ];
    }
}
