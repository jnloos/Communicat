<?php

namespace App\Discussion\Selectors;

use App\Discussion\Agents\JudgeAgent;
use App\Discussion\Memory\Memory;
use App\Discussion\Schemas\DraftChoiceSchema;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\Support\SeededShuffle;
use App\Discussion\Support\TieBreaker;
use App\Discussion\TurnPayload;
use App\Discussion\Values\ModelConfig;
use App\Discussion\Values\Selection;
use App\Discussion\Values\SelectionRecord;
use App\Models\Expert;

/**
 * The JudgeSelector with the judge blind to status: it reads the same drafts
 * and decides by the same one sentence, but never learns who wrote which.
 *
 * Blind means four things. No participant list, so no name, role or token;
 * a history that says what was said but not by or to whom; drafts under a
 * letter instead of their author; and letters handed out in a fresh order
 * every turn, drawn from the project's seed, so a letter never stands for a
 * seat. The order is logged, so a position effect stays measurable.
 *
 * Not blind: what the words themselves say. A draft that opens "As CEO I
 * propose ..." keeps that sentence, and so does a contribution in the
 * history. That content pathway is what this cell isolates -- a title that
 * still wins here wins through what its holder writes, not through the
 * judge reading a name tag. The Long-Term summary names participants too,
 * because the summarizer is told to; a name says nothing about rank unless
 * the discussion itself tied the two together.
 */
class BlindJudgeSelector implements SpeakerSelector
{
    public function __construct(
        private readonly PromptRenderer $prompts,
        private readonly Memory $memory,
        private readonly SeededShuffle $shuffle,
        private readonly TieBreaker $tieBreaker,
    ) {}

    public function select(TurnPayload $payload): Selection
    {
        $project = $payload->project;
        $drafters = $this->labelledDrafters($payload);

        if ($drafters === []) {
            return $this->fallback($payload, $project->contributingExperts()->values()->all(), order: [], label: null, reasoning: '');
        }

        $completion = (new JudgeAgent(
            ModelConfig::fromConfig($project->model),
            $payload->jobLogId,
            schema: DraftChoiceSchema::class,
            schemaArgs: [array_keys($drafters)],
        ))->ask($this->prompts->render('prompts.select.blind-judge', [
            'project' => $project,
            'memory' => $this->memory->viewFor($project),
            'drafts' => array_map(fn (Expert $expert) => $payload->thoughtOf($expert)->proposal, $drafters),
        ]));

        $reasoning = $completion->field('reasoning');
        $label = $completion->field('label');
        $order = $this->order($drafters);
        $speaker = $drafters[$label] ?? null;

        if ($speaker === null) {
            return $this->fallback($payload, array_values($drafters), $order, $label, $reasoning);
        }

        return new Selection(
            speaker: $speaker,
            selector: class_basename(static::class),
            signals: SelectionRecord::blindPickSignals($label, $order, fallback: false),
            reasoning: $reasoning,
        );
    }

    /**
     * Everyone who handed in a draft, in this turn's shuffled order, keyed by
     * the letter their draft stands under.
     *
     * @return array<string, Expert>
     */
    private function labelledDrafters(TurnPayload $payload): array
    {
        $drafters = $payload->project->contributingExperts()
            ->filter(fn (Expert $expert) => $payload->thoughtOf($expert)?->proposal !== null)
            ->values()
            ->all();

        $shuffled = $this->shuffle->shuffle($drafters, $payload->project, $payload->turnIndex);

        return array_combine(array_map(self::label(...), array_keys($shuffled)), $shuffled);
    }

    /** A, B, C, ... -- a group never has more drafts than Project::MAX_CONTRIBUTING_EXPERTS. */
    private static function label(int $position): string
    {
        return chr(ord('A') + $position);
    }

    /**
     * Which expert stood under which letter, for job_logs.selection.
     *
     * @param  array<string, Expert>  $drafters
     * @return array<string, int>
     */
    private function order(array $drafters): array
    {
        return array_map(fn (Expert $expert) => $expert->id, $drafters);
    }

    /**
     * The floor goes to a reproducible random draw when the judge named no
     * letter that was on the table, or when there was no draft to judge.
     *
     * @param  list<Expert>  $candidates
     * @param  array<string, int>  $order
     */
    private function fallback(TurnPayload $payload, array $candidates, array $order, ?string $label, string $reasoning): Selection
    {
        return new Selection(
            speaker: $this->tieBreaker->pick($candidates, $payload->project, $payload->turnIndex),
            selector: class_basename(static::class),
            signals: SelectionRecord::blindPickSignals($label, $order, fallback: true),
            reasoning: $reasoning,
            tieBroken: true,
        );
    }
}
