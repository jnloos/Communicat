<?php

namespace App\Discussion\Schemas;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The judge's scores for the drafts of one round.
 *
 * The judge scores but never names a winner: JudgeSelector derives it from the
 * numbers, so the prose cannot contradict them -- the same rule
 * HighestBidSelector applies to the bids.
 */
class DraftScoresSchema implements ResponseSchema
{
    public const LOWEST = 1;

    public const HIGHEST = 10;

    public function fields(JsonSchema $schema): array
    {
        return [
            'scores' => $schema->array()
                ->items($schema->object([
                    'expert' => $schema->string()
                        ->description('The token of the expert whose draft this scores, for example E7.')
                        ->required(),
                    'score' => $schema->integer()
                        ->min(self::LOWEST)
                        ->max(self::HIGHEST)
                        ->description('How well this draft moves the discussion on right now.')
                        ->required(),
                ]))
                ->description('One entry per draft you were shown. Do not name a winner; the scores decide.')
                ->required(),

            'reasoning' => $schema->string()
                ->description('What separated the drafts, in one or two sentences. Not shown to anyone in the discussion.')
                ->required(),
        ];
    }
}
