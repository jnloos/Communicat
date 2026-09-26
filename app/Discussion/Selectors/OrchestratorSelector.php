<?php

namespace App\Discussion\Selectors;

use App\Discussion\Agents\SelectorAgent;
use App\Discussion\Memory\Memory;
use App\Discussion\Schemas\SpeakerChoiceSchema;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\Support\TieBreaker;
use App\Discussion\TurnPayload;
use App\Discussion\Values\ModelConfig;
use App\Discussion\Values\Selection;
use App\Models\Expert;
use App\Models\Project;

/**
 * One model call picks the next speaker from the visible history, after AutoGen's
 * GroupChatManager. It sees History and Long-Term but never a private thought:
 * Memory::viewFor() without an expert leaves the Short-Term layer empty.
 *
 * It only picks. No role, no agenda, no instruction reaches the speaker — that
 * would steer how much the speaker writes, which is the study's second metric.
 */
class OrchestratorSelector implements SpeakerSelector
{
    public function __construct(
        private readonly PromptRenderer $prompts,
        private readonly Memory $memory,
        private readonly TieBreaker $tieBreaker,
    ) {}

    public function select(TurnPayload $payload): Selection
    {
        $project = $payload->project;

        $completion = (new SelectorAgent(
            ModelConfig::fromConfig($project->model),
            $payload->jobLogId,
            schema: SpeakerChoiceSchema::class,
        ))->ask($this->prompts->render('prompts.select.orchestrator', [
            'project' => $project,
            'memory' => $this->memory->viewFor($project),
        ] + $this->prompts->participants($project)));

        $reasoning = trim($completion->structured['reasoning'] ?? '');
        $token = trim($completion->structured['speaker'] ?? '');
        $speaker = $this->resolve($project, $token);

        if ($speaker !== null) {
            return new Selection(
                speaker: $speaker,
                selector: class_basename(static::class),
                signals: ['token' => $token, 'fallback' => false],
                reasoning: $reasoning,
            );
        }

        return new Selection(
            speaker: $this->tieBreaker->pick($project->contributingExperts()->values()->all(), $project, $payload->turnIndex),
            selector: class_basename(static::class),
            signals: ['token' => $token, 'fallback' => true],
            reasoning: $reasoning,
            tieBroken: true,
        );
    }

    /**
     * Only a contributing expert may speak. contributorByPromptId() resolves an E
     * token through contributorMap(), which holds exactly the contributing experts,
     * so the instanceof check is all that is left: a user token (U3) or an unknown
     * id lands on the fallback.
     */
    private function resolve(Project $project, string $token): ?Expert
    {
        $contributor = $project->contributorByPromptId($token);

        return $contributor instanceof Expert ? $contributor : null;
    }
}
