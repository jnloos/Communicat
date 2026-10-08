<?php

namespace App\Discussion\Metrics\References;

/** The known references by their saved `type`. */
final class AgentReferences
{
    /** @var array<string, class-string<AgentReference>> */
    public const TYPES = [
        PersonaTagsReference::TYPE => PersonaTagsReference::class,
        ExpertNameReference::TYPE => ExpertNameReference::class,
        TopReference::TYPE => TopReference::class,
        EachReference::TYPE => EachReference::class,
    ];

    /** An unknown type reads as `each`: it hides nothing and changes no number silently. */
    public static function fromArray(array $data): AgentReference
    {
        $class = self::TYPES[$data['type'] ?? ''] ?? EachReference::class;

        return $class::fromArray($data);
    }
}
