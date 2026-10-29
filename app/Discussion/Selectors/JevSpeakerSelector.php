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
 * The OrchestratorSelector's job, done by Jev instead of the team's model: it
 * reads History and Long-Term and the participant list (roles as far as the
 * project's attribute visibility shows them) and picks who speaks next. One
 * `choice` question; the options are the contributing experts' tokens, each
 * described as name plus roster label.
 *
 * The state is the text the orchestrator reads (prompts/jev/orchestrator, a
 * test pins it to select/orchestrator). The instructions are not the
 * orchestrator's decision sentence any more: asked who "contributes the most",
 * Jev counts and picks whoever has spoken most, which in a live run is whoever
 * won the first turn, all 60 turns (see
 * docs/reports/2026-10-19-jev-wording-vergleich.md). Like the orchestrator it
 * never sees a private thought (Memory::viewFor() without an expert) and never
 * instructs the speaker. The decision maker is the same for every team model.
 */
class JevSpeakerSelector implements SpeakerSelector
{
    /** What Jev is asked: who is most likely to speak next, not who has contributed the most (V1 of the wording comparison). */
    public const DECISION = 'Choose exactly one person from the participant list who is most likely to speak next. You may choose the same person several times in a row.';

    public function __construct(
        private readonly PromptRenderer $prompts,
        private readonly Memory $memory,
        private readonly JevPicker $picker,
    ) {}

    public function select(TurnPayload $payload): Selection
    {
        $project = $payload->project;
        $experts = $project->contributingExperts()->keyBy(fn (Expert $expert) => $expert->promptId)->all();

        $pick = $this->picker->pick(
            $payload,
            Purpose::Select,
            $this->prompts->render('prompts.jev.orchestrator', [
                'project' => $project,
                'memory' => $this->memory->viewFor($project),
            ] + $this->prompts->participants($project)),
            self::DECISION,
            $experts,
            array_map(fn (Expert $expert) => $expert->name.PersonaLines::for($project, $expert)->rosterLabel(), $experts),
        );

        return $pick->toSelection(class_basename(static::class), SelectionRecord::jevPickSignals($pick));
    }
}
