<?php

namespace App\Discussion\Schemas;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The rolling long-term memory of a discussion.
 *
 * It replaces the previous summary wholesale, which is why an empty answer is
 * never accepted: writing one would erase the study's long-term memory.
 */
class SummarySchema implements ResponseSchema
{
    public function fields(JsonSchema $schema): array
    {
        return [
            'summary' => $schema->string()
                ->description('The continued summary, replacing the previous one in full: neutral prose, no headings, no labels.')
                ->required(),
        ];
    }
}
