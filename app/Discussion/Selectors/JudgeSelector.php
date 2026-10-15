<?php

namespace App\Discussion\Selectors;

use App\Discussion\Agents\JudgeAgent;
use App\Discussion\Memory\Memory;
use App\Discussion\Schemas\SpeakerChoiceSchema;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\Support\TieBreaker;
use App\Discussion\TurnPayload;
use App\Discussion\Values\ModelConfig;
use App\Discussion\Values\Selection;
use App\Models\Expert;

/**
 * One model call reads the drafts the agents wrote in ThinkAndPropose and picks
 * the one that gets the floor.
 *
 * It picks, it does not score. The judge only has to grant the floor once, and a
 * scale let it hand two drafts the same top mark: in the first Quality series it
 * did so in up to half the turns of a cell, and each of those went to a random
 * draw -- the study's null hypothesis standing in for a decision. A single choice
 * from a closed list of the drafters' tokens cannot tie.
 *
 * Like the orchestrator, the judge sees the participant list with every role.
 * Status is the study's manipulation; a judge that met it only through what the
 * drafts happen to say could not show whether it favours a title itself.
 */
class JudgeSelector implements SpeakerSelector
{
    public function __construct(
        private readonly PromptRenderer $prompts,
        private readonly Memory $memory,
        private readonly TieBreaker $tieBreaker,
    ) {}

    public function select(TurnPayload $payload): Selection
    {
        $project = $payload->project;
        $drafters = $project->contributingExperts()
            ->filter(fn (Expert $expert) => $payload->thoughtOf($expert)?->proposal !== null)
            ->keyBy(fn (Expert $expert) => $expert->promptId);

        if ($drafters->isEmpty()) {
            return $this->fallback($payload, $project->contributingExperts()->values()->all(), token: null, reasoning: '');
        }

        $completion = (new JudgeAgent(
            ModelConfig::fromConfig($project->model),
            $payload->jobLogId,
            schema: SpeakerChoiceSchema::class,
            schemaArgs: [$drafters->keys()->all()],
        ))->ask($this->prompts->render('prompts.select.judge', [
            'project' => $project,
            'memory' => $this->memory->viewFor($project),
            'drafts' => $drafters->map(fn (Expert $expert) => [
                'name' => $expert->name,
                'draft' => $payload->thoughtOf($expert)->proposal,
            ])->all(),
        ] + $this->prompts->participants($project)));

        $reasoning = $completion->field('reasoning');
        $token = $completion->field('speaker');
        $speaker = $drafters->get($token);

        if ($speaker === null) {
            return $this->fallback($payload, $drafters->values()->all(), $token, $reasoning);
        }

        return new Selection(
            speaker: $speaker,
            selector: class_basename(static::class),
            signals: ['token' => $token, 'fallback' => false],
            reasoning: $reasoning,
        );
    }

    /**
     * The floor goes to a reproducible random draw when the judge named nobody
     * it could have named, or when there was no draft to judge.
     *
     * @param  list<Expert>  $candidates
     */
    private function fallback(TurnPayload $payload, array $candidates, ?string $token, string $reasoning): Selection
    {
        return new Selection(
            speaker: $this->tieBreaker->pick($candidates, $payload->project, $payload->turnIndex),
            selector: class_basename(static::class),
            signals: ['token' => $token, 'fallback' => true],
            reasoning: $reasoning,
            tieBroken: true,
        );
    }
}
