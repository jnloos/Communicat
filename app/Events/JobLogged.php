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

    public function broadcastOn(): array {
        return [new PrivateChannel('debug')];
    }

    public function broadcastAs(): string {
        return 'JobLogUpdated';
    }

    public function broadcastWith(): array {
        // Debug panel reloads from DB; omit stack traces so failure broadcasts
        // stay under Reverb's default 10KB max_message_size.
        $payload = $this->log->payload;
        if (is_array($payload)) {
            unset($payload['trace']);
        }

        $data = [
            'id'          => $this->log->id,
            'job_class'   => $this->log->job_class,
            'project_id'  => $this->log->project_id,
            'status'      => $this->log->status,
            'started_at'  => $this->log->started_at?->toISOString(),
            'finished_at' => $this->log->finished_at?->toISOString(),
            'duration'    => $this->log->duration(),
            'payload'     => $payload,
        ];
        // #region agent log
        $encoded = json_encode($data);
        $max = (int) config('reverb.apps.apps.0.max_message_size', 10000);
        file_put_contents(base_path('.cursor/debug-c0b61f.log'), json_encode([
            'sessionId' => 'c0b61f',
            'runId' => 'post-fix',
            'hypothesisId' => 'C',
            'location' => 'JobLogged.php:broadcastWith',
            'message' => 'JobLogged broadcast size vs Reverb limit',
            'data' => [
                'status' => $this->log->status,
                'broadcast_bytes' => strlen($encoded ?: ''),
                'reverb_max_message_size' => $max,
                'exceeds_limit' => strlen($encoded ?: '') >= $max,
                'has_trace' => is_array($this->log->payload) && isset($this->log->payload['trace']),
                'broadcast_has_trace' => is_array($payload) && isset($payload['trace']),
            ],
            'timestamp' => (int) (microtime(true) * 1000),
        ])."\n", FILE_APPEND);
        // #endregion

        return $data;
    }
}
