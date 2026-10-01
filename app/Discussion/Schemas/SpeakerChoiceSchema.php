<?php

namespace App\Discussion\Schemas;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * A pick of the next speaker -- the orchestrator's from the history, the judge's
 * from the drafts.
 *
 * It names a speaker and says why, and nothing else: an instruction to the
 * speaker would steer how much that speaker writes, which is the study's second
 * measure.
 */
class SpeakerChoiceSchema implements ResponseSchema
{
    /**
     * @param  array<int, string>  $tokens  the tokens that may be picked, e.g. ['E1', 'E4']
     *
     * Passing them turns the field into a closed list, which the provider
     * enforces. A free string is only asked to hold a token, and a smaller
     * model answers "Kim Hofer [E4]" often enough to matter: that reads as
     * unresolvable, the floor falls to the tie breaker, and a random draw
     * replaces a decision the model had actually made. Random is this study's
     * null hypothesis, so those turns pull every measured effect towards zero.
     */
    public function __construct(private readonly array $tokens = []) {}

    public function fields(JsonSchema $schema): array
    {
        $speaker = $schema->string()
            ->description('The token of the expert who speaks next, for example E7. A token, never a name.')
            ->required();

        return [
            'speaker' => $this->tokens === [] ? $speaker : $speaker->enum($this->tokens),

            'reasoning' => $schema->string()
                ->description('Why this expert, in one or two sentences. Not shown to anyone in the discussion.')
                ->required(),
        ];
    }
}
