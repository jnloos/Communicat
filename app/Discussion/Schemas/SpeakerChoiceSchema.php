<?php

namespace App\Discussion\Schemas;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The orchestrator's pick of the next speaker.
 *
 * It names a speaker and says why, and nothing else: an instruction to the
 * speaker would steer how much that speaker writes, which is the study's second
 * measure.
 */
class SpeakerChoiceSchema implements ResponseSchema
{
    public function fields(JsonSchema $schema): array
    {
        return [
            'speaker' => $schema->string()
                ->description('The token of the expert who speaks next, for example E7. A token from the participant list, never a name.')
                ->required(),

            'reasoning' => $schema->string()
                ->description('Why this expert, in one or two sentences. Not shown to anyone in the discussion.')
                ->required(),
        ];
    }
}
