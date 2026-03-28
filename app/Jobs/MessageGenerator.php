<?php

namespace App\Jobs;

use App\Events\JobLogUpdated;
use App\Events\MessageGenerated;
use App\Jobs\Dependencies\ProjectJob;
use App\Models\JobLog;
use App\Models\Project;
use App\Services\Assistant;
use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class MessageGenerator extends ProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;

    public function __construct(int $projectId) {
        $this->setProject($projectId);
    }

    public function handle(): void {
        $this->withProjectLock(function (Project $project) {
            $log = JobLog::create([
                'job_class'  => static::class,
                'project_id' => $project->id,
                'status'     => 'running',
                'started_at' => now(),
            ]);
            JobLogUpdated::dispatch($log);

            try {
                Assistant::forProject($project)->genNextMessage();
                $log->update(['status' => 'success', 'finished_at' => now()]);
                JobLogUpdated::dispatch($log->fresh());
            } catch (Exception $e) {
                Log::error(sprintf("%s: %s", $e->getMessage(), $e->getTraceAsString()));
                $log->update([
                    'status'      => 'failed',
                    'finished_at' => now(),
                    'payload'     => ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()],
                ]);
                JobLogUpdated::dispatch($log->fresh());
            }

            MessageGenerated::dispatch($project->id);
        });
    }
}
