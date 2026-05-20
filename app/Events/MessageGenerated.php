<?php

namespace App\Events;

use App\Models\Expert;
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
        $addressedId = null;

        if ($message && is_string($message->next_speaker) && trim($message->next_speaker) !== '') {
            $target = mb_strtolower(trim($message->next_speaker));
            if (! in_array($target, ['nutzer', 'user'], true)) {
                $addressedId = Expert::query()
                    ->whereRaw('LOWER(name) = ?', [$target])
                    ->whereHas('projects', fn($q) => $q->whereKey($this->projectId))
                    ->value('id');
            }
        }

        return [
            'project_id'          => $this->projectId,
            'message_id'          => $this->messageId,
            'expert_id'           => $message?->expert_id,
            'addressed_expert_id' => $addressedId,
        ];
    }
}
