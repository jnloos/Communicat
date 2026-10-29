<?php

namespace App\Discussion\Selectors;

use App\Discussion\Schemas\ThoughtWithPrioritySchema;
use App\Discussion\Support\TieBreaker;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Selection;
use App\Discussion\Values\SelectionRecord;
use App\Models\Expert;

/**
 * HighestBidSelector for a Jev Score cell whose bids are compared UNROUNDED
 * (config('ai.jev.bid_resolution') = 'unrounded', see BidResolution): the floor
 * goes to the agent with the highest expected level Jev gave, and only agents with
 * exactly the same score (to the sixth place) tie, which the TieBreaker decides
 * from the project seed. No model call of its own, and no balancing: the same
 * agent may take the floor any number of times in a row.
 *
 * It reads the unrounded scores JevBids left in the selection signals, and
 * records the same bids and fallbacks HighestBidSelector would (the rounded bid
 * stays what the export calls the speaker's bid; an unreadable one counts as the
 * lowest and is listed in fallbacks), plus jev_tie_pool, the number of agents
 * that shared the top score.
 */
class JevBidSelector implements SpeakerSelector
{
    public function __construct(private readonly TieBreaker $tieBreaker) {}

    public function select(TurnPayload $payload): Selection
    {
        $experts = $payload->project->contributingExperts();
        $scores = SelectionRecord::jevScoresIn($payload->selectionSignals());

        $bids = [];
        $fallbacks = [];
        $exact = [];

        foreach ($experts as $expert) {
            $token = $expert->promptId;
            $score = $scores[$token] ?? null;

            if ($score === null) {
                $fallbacks[] = $token;
            }

            $exact[$token] = $score ?? (float) ThoughtWithPrioritySchema::LOWEST;
            $bids[$token] = $payload->thoughtOf($expert)?->priority ?? ThoughtWithPrioritySchema::LOWEST;
        }

        $top = max($exact);
        $leaders = $experts->filter(fn (Expert $expert) => $exact[$expert->promptId] === $top)->values()->all();
        $tie = count($leaders) > 1;

        return new Selection(
            speaker: $tie ? $this->tieBreaker->pick($leaders, $payload->project, $payload->turnIndex) : $leaders[0],
            selector: class_basename(static::class),
            signals: SelectionRecord::jevBidPickSignals($bids, $fallbacks, count($leaders)),
            tieBroken: $tie,
        );
    }
}
