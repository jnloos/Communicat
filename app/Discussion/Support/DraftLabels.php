<?php

namespace App\Discussion\Support;

use App\Discussion\TurnPayload;
use App\Models\Expert;

/**
 * Who stands under which letter in a blind judgement: everyone who handed in a
 * draft, in this turn's seeded shuffle, labelled A, B, C ... The same rule
 * BlindJudgeSelector applies (same sort, same SeededShuffle, same seed and
 * turn index), so a Jev blind judge and the team model's blind judge see the
 * same letter for the same author in the same turn.
 */
class DraftLabels
{
    public function __construct(private readonly SeededShuffle $shuffle) {}

    /** @return array<string, Expert> letter => drafter */
    public function for(TurnPayload $payload): array
    {
        $drafters = $payload->project->contributingExperts()
            ->filter(fn (Expert $expert) => $payload->thoughtOf($expert)?->proposal !== null)
            ->values()
            ->all();

        $shuffled = $this->shuffle->shuffle($drafters, $payload->project, $payload->turnIndex);

        return array_combine(array_map(fn (int $position) => chr(ord('A') + $position), array_keys($shuffled)), $shuffled);
    }
}
