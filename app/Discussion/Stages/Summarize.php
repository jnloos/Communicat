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
 * Maintains the shared Long-Term memory. Runs once more than n + b messages
 * are unsummarized and folds all but the newest n into the rolling summary,
 * so the History window always stays between n and n + b.
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
        $keep = (int) config('discussion.history_keep');
        $batch = (int) config('discussion.summarize_batch');

        $pending = $project->participantMessages()
            ->where('id', '>', $project->summarized_until_message_id ?? 0)
            ->with(['expert:id,name', 'user:id,name'])
            ->orderBy('id')
            ->get();

        if ($pending->count() > $keep + $batch) {
            $toCompress = $pending->take($pending->count() - $keep);

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
