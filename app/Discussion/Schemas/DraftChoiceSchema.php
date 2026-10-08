<?php

namespace App\Discussion\Schemas;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The blind judge's pick: the label of one anonymous draft, and why.
 *
 * SpeakerChoiceSchema asks for an expert's token; the blind judge has never
 * seen one, so it answers with the letter its draft stood under. The labels
 * are a closed list for the reason given there -- a free string invites an
 * answer that resolves to nobody and hands the floor to a random draw.
 */
class DraftChoiceSchema implements ResponseSchema
{
    /** @param  list<string>  $labels  the labels that may be picked, e.g. ['A', 'B', 'C'] */
    public function __construct(private readonly array $labels) {}

    public function fields(JsonSchema $schema): array
    {
        return [
            'label' => $schema->string()
                ->description('The label of the chosen draft, for example A.')
                ->enum($this->labels)
                ->required(),

            'reasoning' => $schema->string()
                ->description('Why this draft, in one or two sentences. Not shown to anyone in the discussion.')
                ->required(),
        ];
    }
}
