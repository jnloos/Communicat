<?php

namespace App\Discussion\Selectors;

use App\Discussion\Agents\JudgeAgent;
use App\Discussion\Memory\Memory;
use App\Discussion\Support\PromptRenderer;
use App\Discussion\Support\Thinking;
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
    public const MARKER_SCORE = 'BEWERTUNG:';

    public const MARKER_REASONING = 'BEGRÜNDUNG:';

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
        ))->ask($this->prompts->render('prompts.select.judge', [
            'project' => $project,
            'memory' => $this->memory->viewFor($project),
            'drafts' => $drafts,
            'marker_score' => self::MARKER_SCORE,
            'marker_reasoning' => self::MARKER_REASONING,
        ] + $this->prompts->participants($project)));

        $reasoning = Thinking::section($completion->text, self::MARKER_REASONING);
        $scores = $this->scores($completion->text, $experts->all());

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
     * Every "BEWERTUNG: E7 8" line whose token is a contributing expert.
     *
     * @param  list<Expert>  $experts
     * @return array<string, int>
     */
    private function scores(string $text, array $experts): array
    {
        $known = [];
        foreach ($experts as $expert) {
            $known[$expert->promptId] = true;
        }

        preg_match_all('/'.preg_quote(self::MARKER_SCORE, '/').'\s*(\S+)\s+(\d+)/u', $text, $matches, PREG_SET_ORDER);

        $scores = [];
        foreach ($matches as [, $token, $score]) {
            if (isset($known[$token])) {
                $scores[$token] = (int) $score;
            }
        }

        return $scores;
    }
}
