<?php

namespace App\Discussion\Selectors;

use App\Discussion\Jev\JevPicker;
use App\Discussion\Memory\Memory;
use App\Discussion\Support\PersonaLines;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Purpose;
use App\Discussion\Values\Selection;
use App\Discussion\Values\SelectionRecord;
use App\Models\Expert;

/**
 * The JudgeSelector's job, done by Jev: one `choice` question over the drafts
 * the agents wrote in ThinkAndPropose, the options being the drafters' tokens.
 *
 * Like JudgeSelector it sees the participant list with the roles and, unlike
 * the orchestrator, private material: the drafts. The full drafts are in the
 * state (prompts/jev/judge); an option's description is only who it is ("name
 * (role)", as in the orchestrator), because repeating a draft's opening there
 * would only be noise. The decision is the judge's sentence word for word.
 *
 * No draft handed in: no question to ask, the floor goes to a draw among
 * everyone, recorded as a fallback, as JudgeSelector does.
 */
class JevJudgeSelector implements SpeakerSelector
{
    /** The decision of select/judge, word for word. */
    public const DECISION = 'Choose exactly one draft that contributes the most to the conversation right now.';

    public function __construct(
        private readonly PromptRenderer $prompts,
        private readonly Memory $memory,
        private readonly JevPicker $picker,
    ) {}

    public function select(TurnPayload $payload): Selection
    {
        $project = $payload->project;
        $drafters = $project->contributingExperts()
            ->filter(fn (Expert $expert) => $payload->thoughtOf($expert)?->proposal !== null)
            ->keyBy(fn (Expert $expert) => $expert->promptId)
            ->all();

        $pick = $drafters === []
            ? $this->picker->unasked($payload, $project->contributingExperts()->values()->all())
            : $this->picker->pick(
                $payload,
                Purpose::Judge,
                $this->prompts->render('prompts.jev.judge', [
                    'project' => $project,
                    'memory' => $this->memory->viewFor($project),
                    'drafts' => array_map(fn (Expert $expert) => [
                        'name' => $expert->name,
                        'draft' => $payload->thoughtOf($expert)->proposal,
                    ], $drafters),
                ] + $this->prompts->participants($project)),
                self::DECISION,
                $drafters,
                array_map(fn (Expert $expert) => $expert->name.PersonaLines::for($project, $expert)->rosterLabel(), $drafters),
            );

        return $pick->toSelection(class_basename(static::class), SelectionRecord::jevPickSignals($pick));
    }
}
