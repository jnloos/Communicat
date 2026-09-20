<?php

namespace App\Events;

use App\Models\Expert;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Live progress of the message-generation pipeline, purely for UI feedback
 * (thinking bubbles / typing indicator). Stages, in turn order:
 *   'routing'  — the moderator is picking candidates (no experts yet)
 *   'thinking' — the candidate experts run THINK (experts = candidates)
 *   'speaking' — the winner writes the visible turn (experts = [winner])
 * Cleared client-side by MessageGenerated / GenerationStopped.
 */
class PipelineStageChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<int, array{id: int, name: string, avatar_url: ?string}>  $experts
     */
    public function __construct(
        public readonly int $projectId,
        public readonly string $stage,
        public readonly array $experts = [],
    ) {}

    /**
     * @param  iterable<Expert>  $experts  the experts the indicator should show
     */
    public static function announce(int $projectId, string $stage, iterable $experts = []): void
    {
        $shown = [];

        foreach ($experts as $expert) {
            $shown[] = ['id' => $expert->id, 'name' => $expert->name, 'avatar_url' => $expert->avatar_url];
        }

        static::dispatch($projectId, $stage, $shown);
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("projects.{$this->projectId}")];
    }

    public function broadcastAs(): string
    {
        return 'PipelineStageChanged';
    }

    public function broadcastWith(): array
    {
        return [
            'stage' => $this->stage,
            'experts' => $this->experts,
        ];
    }
}
