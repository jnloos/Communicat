<?php

namespace App\Discussion\Jev;

use App\Discussion\Values\JevBid;
use App\Discussion\Values\JevDecisionModel;
use App\Discussion\Values\Purpose;
use Laravel\Ai\Classification\Score;

/**
 * Jev's answer to "how urgently does this agent want the floor now", for one
 * agent, on the scale of the agents' own bids (1 to 5, the levels worded like
 * the bidding prompt's anchors). One request per agent: the state is that
 * agent's own view, so a bid stays an agent's, not a ranking of the group.
 *
 * The state (prompts/jev/bid) is written to the agent ("you"), so the question
 * is too.
 */
class JevBidder
{
    public const QUESTION = 'urgency';

    public const INSTRUCTIONS = 'How urgently do you want to take the floor right now?';

    /** Lowest level first; the count must equal ThoughtWithPrioritySchema::HIGHEST - LOWEST + 1. */
    public const LEVELS = [
        'Nothing to contribute right now.',
        'Little to contribute right now.',
        'Something worthwhile to contribute right now.',
        'A lot to contribute right now.',
        'A contribution that is absolutely necessary right now.',
    ];

    public function __construct(private readonly JevClient $jev) {}

    /** Returns scalars only: it runs inside a child process when the bids are asked in parallel. */
    public function bid(JevDecisionModel $model, string $state, ?int $jobLogId, int $expertId): JevBid
    {
        $reply = $this->jev->ask($model, $state, [self::QUESTION => new Score(self::INSTRUCTIONS, self::LEVELS)], Purpose::Think, $jobLogId, $expertId);
        $score = $reply->score(self::QUESTION, count(self::LEVELS));

        return new JevBid($score?->value, $score?->confidence, $reply->servedModel(), $reply->latencyMs);
    }
}
