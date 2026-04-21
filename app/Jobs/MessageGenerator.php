<?php

namespace App\Jobs;

use App\Events\JobLogged;
use App\Events\MessageGenerated;
use App\Events\UserInputRequested;
use App\Jobs\Dependencies\ProjectJob;
use App\Models\JobLog;
use App\Models\Project;
use App\Services\OpenAIClient;
use App\Services\PipelineModerator;
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

    public int $timeout = 280;

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
            JobLogged::dispatch($log);

            OpenAIClient::bindJobLog($log->id);

            try {
                $pipelineResult = (new PipelineModerator($project, $log->id))->run();
                $log->update(['status' => 'success', 'finished_at' => now()]);
                JobLogged::dispatch($log->fresh());

                if (!empty($pipelineResult['stop'])) {
                    UserInputRequested::dispatch($project->id, $pipelineResult['reason'] ?? 'stop');
                }
            } catch (Exception $e) {
                Log::error(sprintf("%s: %s", $e->getMessage(), $e->getTraceAsString()));
                $log->update([
                    'status'      => 'failed',
                    'finished_at' => now(),
                    'payload'     => ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()],
                ]);
                JobLogged::dispatch($log->fresh());
            } finally {
                OpenAIClient::bindJobLog(null);
            }

            MessageGenerated::dispatch($project->id);
        });
    }
}
