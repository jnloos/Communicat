<?php

namespace App\Discussion\Support;

use App\Discussion\Agents\ThinkAgent;
use App\Discussion\Memory\Memory;
use App\Discussion\TurnPayload;
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
    ) {}

    /**
     * @param  iterable<Expert>  $experts
     * @return array<int, string> expert id → raw answer
     */
    public function ask(TurnPayload $payload, iterable $experts, string $view, array $data = []): array
    {
        $model = ModelConfig::fromConfig($payload->project->model);
        $shared = $data + ['project' => $payload->project] + $this->prompts->participants($payload->project);

        $answers = [];

        foreach ($experts as $expert) {
            $prompt = $this->prompts->render($view, $shared + [
                'expert' => $expert,
                'memory' => $this->memory->viewFor($payload->project, $expert),
            ]);

            $answers[$expert->id] = (new ThinkAgent($model, $payload->jobLogId, $expert->id))
                ->ask($prompt)
                ->text;
        }

        return $answers;
    }

    /** Short-Term memory is one rolling thought per expert and project. */
    public function remember(Project $project, Expert $expert, string $thought): void
    {
        Summary::updateOrCreate(
            ['project_id' => $project->id, 'expert_id' => $expert->id],
            ['content' => $thought],
        );
    }

    /** The text after $marker, up to $until if given. '' when the marker is missing. */
    public static function section(string $text, string $marker, ?string $until = null): string
    {
        $start = mb_strpos($text, $marker);

        if ($start === false) {
            return '';
        }

        $content = mb_substr($text, $start + mb_strlen($marker));

        if ($until !== null && ($end = mb_strpos($content, $until)) !== false) {
            $content = mb_substr($content, 0, $end);
        }

        return trim($content);
    }
}
