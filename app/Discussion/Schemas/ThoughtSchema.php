<?php

namespace App\Discussion\Schemas;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/** What the selected speaker returns when it only thinks: the rolling thought. */
class ThoughtSchema implements ResponseSchema
{
    public function fields(JsonSchema $schema): array
    {
        return [
            'thought' => $schema->string()
                ->description('Your updated short-term memory: one running thought, at most six sentences, no greeting and no contribution.')
                ->required(),
        ];
    }
}
