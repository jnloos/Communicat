<?php

namespace App\Discussion\Schemas;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * Thought plus a draft contribution, for the Quality pipeline.
 *
 * Every agent drafts, one wins the floor through JudgeSelector. The draft is
 * what the judge scores, and the winner's own draft is handed back to it in the
 * Speak prompt.
 */
class ThoughtWithDraftSchema implements ResponseSchema
{
    public function fields(JsonSchema $schema): array
    {
        return [
            'thought' => $schema->string()
                ->description('Your updated short-term memory: one running thought, at most six sentences, no greeting and no contribution.')
                ->required(),

            'draft' => $schema->string()
                ->description('The contribution you would make if you got the floor now: plain prose, addressing participants by name.')
                ->required(),
        ];
    }
}
