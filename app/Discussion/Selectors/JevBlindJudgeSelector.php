<?php

namespace App\Discussion\Selectors;

use App\Discussion\Jev\JevPicker;
use App\Discussion\Memory\Memory;
use App\Discussion\Support\DraftLabels;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Purpose;
use App\Discussion\Values\Selection;
use App\Discussion\Values\SelectionRecord;
use App\Models\Expert;

/**
 * The BlindJudgeSelector's job, done by Jev: blind to status in the same four
 * ways -- no participant list (so no name, token or role), a history through
 * partials/anonymous-memory (position and content only), drafts under a letter,
 * and letters shuffled every turn from the project seed (DraftLabels, the same
 * rule as BlindJudgeSelector, so a letter means the same author in both).
 * The options are the letters; the letter => expert id order is logged. What the
 * drafts and the history themselves say about their authors stays in, as it does
 * for the team model's blind judge.
 */
class JevBlindJudgeSelector implements SpeakerSelector
{
    /** The decision of select/blind-judge, word for word. */
    public const DECISION = 'Choose exactly one draft that contributes the most to the conversation right now.';

    public function __construct(
        private readonly PromptRenderer $prompts,
        private readonly Memory $memory,
        private readonly DraftLabels $labels,
        private readonly JevPicker $picker,
    ) {}

    public function select(TurnPayload $payload): Selection
    {
        $project = $payload->project;
        $drafters = $this->labels->for($payload);
        $order = array_map(fn (Expert $expert) => $expert->id, $drafters);
        $drafts = array_map(fn (Expert $expert) => $payload->thoughtOf($expert)->proposal, $drafters);

        $pick = $drafters === []
            ? $this->picker->unasked($payload, $project->contributingExperts()->values()->all())
            : $this->picker->pick(
                $payload,
                Purpose::Judge,
                $this->prompts->render('prompts.jev.blind-judge', [
                    'project' => $project,
                    'memory' => $this->memory->viewFor($project),
                    'drafts' => $drafts,
                ]),
                self::DECISION,
                $drafters,
                array_map(fn (string $letter) => 'Draft '.$letter, array_combine(array_keys($drafters), array_keys($drafters))),
            );

        return $pick->toSelection(class_basename(static::class), SelectionRecord::jevBlindPickSignals($pick, $order));
    }
}
