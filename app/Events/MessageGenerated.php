<?php

namespace App\Events;

use App\Models\Message;
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

        return [
            'project_id'          => $this->projectId,
            'message_id'          => $this->messageId,
            'expert_id'           => $message?->expert_id,
            'addressed_expert_id' => $message?->next_speaker_expert_id,
            'addressed_user_id'   => $message?->next_speaker_user_id,
        ];
    }
}
