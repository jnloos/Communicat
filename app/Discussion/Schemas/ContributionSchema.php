<?php

namespace App\Discussion\Schemas;

use Illuminate\Contracts\JsonSchema\JsonSchema;

/**
 * The public contribution of a turn, and whom it addresses.
 *
 * Two fields and no more. `contribution` is the text the study counts, in words
 * and characters, so anything the model puts here it pays for; a third field
 * asking it to label or measure its own turn would take attention away from the
 * contribution itself and change the very quantity being measured.
 */
class ContributionSchema implements ResponseSchema
{
    public function fields(JsonSchema $schema): array
    {
        return [
            'contribution' => $schema->string()
                ->description('Your visible contribution: plain prose, participants addressed by name, never by token. No labels, no headings.')
                ->required(),

            'addressee' => $schema->string()
                ->description('The token of the expert your contribution speaks to, for example E7 — or null when you address the group.')
                ->nullable()
                ->required(),
        ];
    }
}
