<?php

namespace App\Events;

use App\Models\JobLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JobLogged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly JobLog $log) {}

    /**
     * A job report carries prompts and answers, so it goes only to the project's
     * own channel, which its owner and contributors alone may join.
     */
    public function broadcastOn(): array
    {
        if (! $this->broadcastWhen()) {
            return [];
        }

        return [new PrivateChannel('projects.'.$this->log->project_id)];
    }

    /** A job outside any project has no audience. */
    public function broadcastWhen(): bool
    {
        return $this->log->project_id !== null;
    }

    public function broadcastAs(): string
    {
        return 'JobLogUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->log->id,
            'job_class' => $this->log->job_class,
            'project_id' => $this->log->project_id,
            'status' => $this->log->status->value,
            'started_at' => $this->log->started_at?->toISOString(),
            'finished_at' => $this->log->finished_at?->toISOString(),
            'duration' => $this->log->duration(),
            'payload' => $this->log->payload,
        ];
    }
}
