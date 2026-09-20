<?php

namespace App\Discussion\Stages;

use App\Discussion\Memory\Memory;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\TurnPayload;
use App\Llm\LlmFactory;
use App\Llm\LlmRequest;
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
        private readonly LlmFactory $llm,
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

            $response = $this->llm->forProject($project)->complete(new LlmRequest(
                $this->prompts->system(),
                $this->prompts->render('prompts.summarize', [
                    'project' => $project,
                    'previous' => (string) $project->long_term_memory,
                    'entries' => $toCompress->map(fn (Message $message) => $this->memory->describe($message))->all(),
                ]),
                LlmRequest::PURPOSE_SUMMARIZE,
                $payload->jobLogId,
            ));

            $project->long_term_memory = $response->text;
            $project->summarized_until_message_id = $toCompress->last()->id;
            $project->save();
        }

        return $next($payload);
    }
}
