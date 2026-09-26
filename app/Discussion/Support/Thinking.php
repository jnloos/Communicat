<?php

namespace App\Discussion\Support;

use App\Discussion\Agents\ThinkAgent;
use App\Discussion\Memory\Memory;
use App\Discussion\Schemas\ResponseSchema;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Completion;
use App\Discussion\Values\ModelConfig;
use App\Models\Expert;
use App\Models\Project;
use App\Models\Summary;

/** What every Think stage shares: render per expert, ask in parallel, keep the thought. */
class Thinking
{
    public function __construct(
        private readonly PromptRenderer $prompts,
        private readonly Memory $memory,
        private readonly ParallelPrompts $parallel,
    ) {}

    /**
     * Prompt every expert in one parallel round.
     *
     * @param  iterable<Expert>  $experts
     * @param  class-string<ResponseSchema>  $schema  the shape the answer must have
     * @return array<int, array<string, mixed>> expert id → the structured answer
     */
    public function ask(TurnPayload $payload, iterable $experts, string $view, string $schema, array $data = []): array
    {
        $modelKey = $payload->project->model;
        $jobLogId = $payload->jobLogId;
        $shared = $data + ['project' => $payload->project] + $this->prompts->participants($payload->project);

        $tasks = [];

        foreach ($experts as $expert) {
            $prompt = $this->prompts->render($view, $shared + [
                'expert' => $expert,
                'memory' => $this->memory->viewFor($payload->project, $expert),
            ]);

            $expertId = $expert->id;

            // Only scalars are captured: the agent is built inside the child process.
            $tasks[$expertId] = static fn () => (new ThinkAgent(ModelConfig::fromConfig($modelKey), $jobLogId, $expertId, $schema))
                ->ask($prompt);
        }

        return array_map(fn (Completion $completion) => $completion->structured, $this->parallel->run($tasks));
    }

    /** Short-Term memory is one rolling thought per expert and project. */
    public function remember(Project $project, Expert $expert, string $thought): void
    {
        Summary::updateOrCreate(
            ['project_id' => $project->id, 'expert_id' => $expert->id],
            ['content' => $thought],
        );
    }
}
