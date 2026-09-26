<?php

namespace App\Discussion\Stages;

use App\Discussion\Agents\SpeakAgent;
use App\Discussion\Memory\Memory;
use App\Discussion\ParseFailure;
use App\Discussion\Schemas\ContributionSchema;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Completion;
use App\Discussion\Values\Contribution;
use App\Discussion\Values\ModelConfig;
use App\Events\PipelineStageChanged;
use App\Models\Expert;
use App\Models\Project;
use Closure;

/** The selected speaker writes the public contribution. Needs SelectSpeaker before it. */
class Speak
{
    public function __construct(
        private readonly PromptRenderer $prompts,
        private readonly Memory $memory,
    ) {}

    public function handle(TurnPayload $payload, Closure $next)
    {
        $speaker = $payload->selection()->speaker;

        PipelineStageChanged::announce($payload->project->id, 'speaking', [$speaker]);

        $completion = (new SpeakAgent(
            ModelConfig::fromConfig($payload->project->model),
            $payload->jobLogId,
            $speaker->id,
            ContributionSchema::class,
        ))->ask($this->prompt($payload, $speaker));

        $payload->contribute($this->parse($completion, $payload->project));

        return $next($payload);
    }

    private function prompt(TurnPayload $payload, Expert $speaker): string
    {
        return $this->prompts->render('prompts.speak', [
            'expert' => $speaker,
            'project' => $payload->project,
            'memory' => $this->memory->viewFor($payload->project, $speaker),
            'proposal' => $payload->thoughtOf($speaker)?->proposal,
        ] + $this->prompts->participants($payload->project));
    }

    /** The contribution is never parsed; it is counted, so it is taken verbatim. */
    private function parse(Completion $completion, Project $project): Contribution
    {
        $text = trim($completion->structured['contribution'] ?? '');

        // The schema declares the field required. An empty one means the turn
        // produced nothing to show and nothing to count.
        if ($text === '') {
            throw new ParseFailure('Speak: the answer carries no contribution.');
        }

        return new Contribution($text, $this->addresseeToken($completion, $project), $completion);
    }

    /** Only a contributing expert may be addressed; anything else becomes the group. */
    private function addresseeToken(Completion $completion, Project $project): ?string
    {
        $token = $completion->structured['addressee'] ?? null;

        if (! is_string($token) || $token === '') {
            return null;
        }

        return $project->contributorByPromptId($token) instanceof Expert ? $token : null;
    }
}
