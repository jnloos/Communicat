<?php

namespace App\Discussion\Schemas;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;

/**
 * The shape one kind of model answer must have.
 *
 * A stage names a schema by class name rather than handing over an object,
 * because agents are rebuilt inside a child process when prompts run in
 * parallel and only scalars survive that boundary. The same reason
 * SelectSpeaker is parameterised with a selector's class name.
 *
 * Every field the study reads is declared here, so a missing field is the
 * provider refusing the contract rather than a parser guessing at prose.
 */
interface ResponseSchema
{
    /** @return array<string, Type> */
    public function fields(JsonSchema $schema): array;
}
