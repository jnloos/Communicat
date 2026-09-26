<?php

namespace App\Discussion\Schemas;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Thought plus a bid for the floor, for the Score pipeline.
 *
 * The bid is nullable on purpose. HighestBidSelector owns what happens when one
 * is missing and records it in the selection signals, so the study can tell a
 * real bid from a substituted one -- a schema default would hide exactly that.
 */
class ThoughtWithPrioritySchema implements ResponseSchema
{
    public const LOWEST = 1;

    public const HIGHEST = 5;

    public function fields(JsonSchema $schema): array
    {
        return [
            'thought' => $schema->string()
                ->description('Your updated short-term memory: one running thought, at most six sentences, no greeting and no contribution.')
                ->required(),

            'priority' => $schema->integer()
                ->min(self::LOWEST)
                ->max(self::HIGHEST)
                ->description(sprintf(
                    'How urgently you need the floor now: %d if you have nothing to add, %d if your contribution is indispensable. The number alone, no reasoning.',
                    self::LOWEST,
                    self::HIGHEST,
                ))
                ->required(),
        ];
    }
}
