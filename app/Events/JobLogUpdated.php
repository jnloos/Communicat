<?php

namespace App\Events;

use App\Models\JobLog;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class JobLogUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly JobLog $log) {}

    public function broadcastOn(): array {
        return [new PrivateChannel('debug')];
    }

    public function broadcastAs(): string {
        return 'JobLogUpdated';
    }

    public function broadcastWith(): array {
        return [
            'id'          => $this->log->id,
            'job_class'   => $this->log->job_class,
            'project_id'  => $this->log->project_id,
            'status'      => $this->log->status,
            'started_at'  => $this->log->started_at?->toISOString(),
            'finished_at' => $this->log->finished_at?->toISOString(),
            'duration'    => $this->log->duration(),
            'payload'     => $this->log->payload,
        ];
    }
}
