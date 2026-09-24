<?php

namespace App\Discussion\Stages;

use App\Discussion\Agents\SummarizeAgent;
use App\Discussion\Memory\Memory;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\TurnPayload;
use App\Discussion\Values\ModelConfig;
use App\Models\Message;
use Closure;

/**
 * Maintains the shared Long-Term memory. Runs once at least x messages are
 * unsummarized and folds exactly the y oldest of them into the rolling summary,
 * leaving the newer ones verbatim in the History.
 */
class Summarize
{
    public function __construct(
        private readonly PromptRenderer $prompts,
        private readonly Memory $memory,
    ) {}

    public function handle(TurnPayload $payload, Closure $next)
    {
        $project = $payload->project;
        $threshold = $project->summarizeThreshold();

        $pending = $project->participantMessages()
            ->where('id', '>', $project->summarized_until_message_id ?? 0)
            ->with(['expert:id,name', 'user:id,name'])
            ->orderBy('id')
            ->get();

        if ($pending->count() >= $threshold) {
            $toCompress = $pending->take($project->summarizeOldest());

            $completion = (new SummarizeAgent(ModelConfig::fromConfig($project->model), $payload->jobLogId))
                ->ask($this->prompts->render('prompts.summarize', [
                    'project' => $project,
                    'previous' => (string) $project->long_term_memory,
                    'entries' => $toCompress->map(fn (Message $message) => $this->memory->describe($message))->all(),
                ]));

            $project->long_term_memory = $completion->text;
            $project->summarized_until_message_id = $toCompress->last()->id;
            $project->save();
        }

        return $next($payload);
    }
}
