<?php

namespace App\Discussion\Selectors;

use App\Discussion\Support\TieBreaker;
use App\Discussion\TurnPayload;
use App\Discussion\Values\Selection;
use App\Models\Expert;

/**
 * The floor goes to the highest bid the agents made in ThinkAndPrioritize. No
 * model call of its own: the bids are already in the payload, so the selector is
 * pure evaluation.
 *
 * A missing or unreadable bid counts as the lowest and is listed under
 * signals.fallbacks — never as a reason to end the run, and never as a reason to
 * exclude that agent, which would let a single bad answer silence them for good.
 */
class HighestBidSelector implements SpeakerSelector
{
    private const LOWEST = 1;

    public function __construct(private readonly TieBreaker $tieBreaker) {}

    public function select(TurnPayload $payload): Selection
    {
        $experts = $payload->project->contributingExperts();

        $bids = [];
        $fallbacks = [];

        foreach ($experts as $expert) {
            $priority = $payload->thoughtOf($expert)?->priority;

            if ($priority === null) {
                $fallbacks[] = $expert->promptId;
                $priority = self::LOWEST;
            }

            $bids[$expert->promptId] = $priority;
        }

        $top = max($bids);
        $leaders = $experts->filter(fn (Expert $expert) => $bids[$expert->promptId] === $top)->values()->all();
        $tie = count($leaders) > 1;

        return new Selection(
            speaker: $tie ? $this->tieBreaker->pick($leaders, $payload->project, $payload->turnIndex) : $leaders[0],
            selector: class_basename(static::class),
            signals: ['bids' => $bids, 'fallbacks' => $fallbacks],
            tieBroken: $tie,
        );
    }
}
