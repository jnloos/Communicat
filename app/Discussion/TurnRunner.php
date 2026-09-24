<?php

namespace App\Discussion;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Pipelines\TurnPipeline;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\Support\TextLength;
use App\Discussion\Values\ModelConfig;
use App\Events\JobLogged;
use App\Models\JobLog;
use App\Models\Project;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs exactly one turn of whatever pipeline the project names, and measures
 * it. Measuring lives here, not in a stage, so no pipeline can leave it out and
 * failed turns are counted too. Knows nothing about queues, viewers or the UI.
 */
class TurnRunner
{
    public function __construct(
        private readonly PipelineRegistry $pipelines,
        private readonly PromptRenderer $prompts,
    ) {}

    public function run(Project $project): TurnResult
    {
        if ($project->contributingExperts()->isEmpty()) {
            return TurnResult::stop('no_candidates');
        }

        if ($this->budgetIsSpent($project)) {
            return TurnResult::stop('turn_budget');
        }

        $pipeline = $this->pipelines->resolve($project->pipeline);
        $this->snapshotRunConfig($project, $pipeline);

        $log = JobLog::create([
            'job_class' => static::class,
            'project_id' => $project->id,
            'status' => 'running',
            'started_at' => now(),
            'turn_index' => (int) JobLog::where('project_id', $project->id)->max('turn_index') + 1,
        ]);
        JobLogged::dispatch($log);

        $payload = new TurnPayload($project, $log->turn_index, $log->id);

        try {
            app(Pipeline::class)->send($payload)->through($pipeline->stages())->thenReturn();
        } catch (Throwable $e) {
            Log::error(sprintf('Turn %d of project %d failed: %s', $log->turn_index, $project->id, $e->getMessage()), ['exception' => $e]);

            $this->finish($log, $payload, 'failed', class_basename($e).': '.$e->getMessage());

            return TurnResult::stop('failed');
        }

        $this->finish($log, $payload, 'success', null);

        return $payload->stop ? TurnResult::stop((string) $payload->reason) : TurnResult::proceed();
    }

    private function budgetIsSpent(Project $project): bool
    {
        if ($project->turn_budget === null) {
            return false;
        }

        // Counts turns that produced a public contribution, not turns that finished
        // cleanly: PersistMessage saves the message before later stages (e.g. Summarize)
        // run, so a turn can fail after speaking. The study's unit of analysis is the
        // spoken turn, so that turn must still consume budget even though its status
        // ends up 'failed'.
        $done = JobLog::where('project_id', $project->id)->whereNotNull('words')->count();

        return $done >= $project->turn_budget;
    }

    /** Freeze what this run means, once, so it stays reproducible after the code moves on. */
    private function snapshotRunConfig(Project $project, TurnPipeline $pipeline): void
    {
        if ($project->run_config !== null) {
            return;
        }

        $project->run_config = [
            'pipeline' => $project->pipeline,
            'stages' => $pipeline->stages(),
            'model' => ModelConfig::fromConfig($project->model)->toArray(),
            'history_keep' => (int) config('discussion.history_keep'),
            'summarize_batch' => (int) config('discussion.summarize_batch'),
            'system_prompt' => $this->prompts->system(),
        ];
        $project->save();
    }

    private function finish(JobLog $log, TurnPayload $payload, string $status, ?string $error): void
    {
        $log->update(['status' => $status, 'finished_at' => now(), 'error' => $error] + $this->measure($payload));

        JobLogged::dispatch($log->fresh());
    }

    /** Only the public contribution counts towards words and chars; thoughts are measured apart. */
    private function measure(TurnPayload $payload): array
    {
        $measured = [];

        if ($payload->hasSelection()) {
            $speaker = $payload->selection()->speaker;
            $thought = $payload->thoughtOf($speaker);

            $measured += [
                'expert_id' => $speaker->id,
                'seat' => $speaker->pivot?->seat,
                'selection' => $payload->selection()->toArray(),
                'thought_words' => $thought === null ? null : TextLength::words($thought->text),
                'thought_chars' => $thought === null ? null : TextLength::chars($thought->text),
            ];
        }

        if ($payload->hasContribution()) {
            $contribution = $payload->contribution();

            $measured += [
                'words' => TextLength::words($contribution->text),
                'chars' => TextLength::chars($contribution->text),
                'reasoning_tokens' => $contribution->completion->reasoningTokens,
            ];
        }

        return $measured;
    }
}
