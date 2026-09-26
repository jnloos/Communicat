<?php

namespace App\Discussion\Selectors;

use App\Discussion\Agents\JudgeAgent;
use App\Discussion\Memory\Memory;
use App\Discussion\Schemas\DraftScoresSchema;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\Support\TieBreaker;
use App\Discussion\TurnPayload;
use App\Discussion\Values\ModelConfig;
use App\Discussion\Values\Selection;
use App\Models\Expert;

/**
 * One model call scores the drafts the agents wrote in ThinkAndPropose; the
 * highest score gets the floor.
 *
 * The judge scores but never names a winner: the selector derives it from the
 * numbers, so the answer cannot contradict itself, and the rule is the same one
 * HighestBidSelector applies to the bids.
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
        $experts = $project->contributingExperts();

        $drafts = [];
        foreach ($experts as $expert) {
            $proposal = $payload->thoughtOf($expert)?->proposal;

            if ($proposal !== null) {
                $drafts[$expert->promptId] = ['name' => $expert->name, 'draft' => $proposal];
            }
        }

        $completion = (new JudgeAgent(
            ModelConfig::fromConfig($project->model),
            $payload->jobLogId,
            schema: DraftScoresSchema::class,
        ))->ask($this->prompts->render('prompts.select.judge', [
            'project' => $project,
            'memory' => $this->memory->viewFor($project),
            'drafts' => $drafts,
            'lowest' => DraftScoresSchema::LOWEST,
            'highest' => DraftScoresSchema::HIGHEST,
        ] + $this->prompts->participants($project)));

        $reasoning = trim($completion->structured['reasoning'] ?? '');
        $scores = $this->scores($completion->structured['scores'] ?? [], $experts->all());

        $fallbacks = [];
        foreach ($experts as $expert) {
            if (! isset($scores[$expert->promptId])) {
                $fallbacks[] = $expert->promptId;
            }
        }

        if ($scores === []) {
            return new Selection(
                speaker: $this->tieBreaker->pick($experts->values()->all(), $project, $payload->turnIndex),
                selector: class_basename(static::class),
                signals: ['scores' => [], 'fallbacks' => $fallbacks, 'fallback' => true],
                reasoning: $reasoning,
                tieBroken: true,
            );
        }

        $top = max($scores);
        $leaders = $experts->filter(fn (Expert $expert) => ($scores[$expert->promptId] ?? null) === $top)->values()->all();
        $tie = count($leaders) > 1;

        return new Selection(
            speaker: $tie ? $this->tieBreaker->pick($leaders, $project, $payload->turnIndex) : $leaders[0],
            selector: class_basename(static::class),
            signals: ['scores' => $scores, 'fallbacks' => $fallbacks, 'fallback' => false],
            reasoning: $reasoning,
            tieBroken: $tie,
        );
    }

    /**
     * The scored entries whose token is a contributing expert. A token the
     * judge invented, or an entry without a usable number, is dropped rather
     * than guessed at -- the expert then shows up in the fallbacks.
     *
     * @param  mixed  $rows  the schema's `scores` array, as the provider sent it
     * @param  list<Expert>  $experts
     * @return array<string, int>
     */
    private function scores(mixed $rows, array $experts): array
    {
        $known = [];
        foreach ($experts as $expert) {
            $known[$expert->promptId] = true;
        }

        $scores = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            $token = is_array($row) ? ($row['expert'] ?? null) : null;
            $score = is_array($row) ? ($row['score'] ?? null) : null;

            if (! is_string($token) || ! isset($known[$token])) {
                continue;
            }

            if (! is_int($score) && ! (is_string($score) && ctype_digit($score))) {
                continue;
            }

            $scores[$token] = (int) $score;
        }

        return $scores;
    }
}
