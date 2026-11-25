<?php

namespace App\Discussion\Jev;

use App\Discussion\Values\JevBid;
use App\Discussion\Values\JevDecisionModel;
use App\Discussion\Values\Purpose;
use Laravel\Ai\Classification\Score;

/**
 * Jev's answer to "how urgently does this agent want the floor now", for one
 * agent, on Jev's own five-level scale (1 to 5). The team's bid runs 0 to 9
 * since 25.11.2026; Jev Score never asks the team for a bid, so the two scales
 * never meet in one turn. One request per agent: the state is that
 * agent's own view, so a bid stays an agent's, not a ranking of the group.
 *
 * The state (prompts/jev/bid) is written to the agent ("you"), so the question
 * is too.
 */
class JevBidder
{
    public const QUESTION = 'urgency';

    public const INSTRUCTIONS = 'How urgently do you want to take the floor right now?';

    /** Jev's lowest level as JevScore counts it; what an unreadable Jev bid counts as. */
    public const LOWEST = 1;

    /** Lowest level first; JevScore reads them as LOWEST to count(LEVELS). */
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
