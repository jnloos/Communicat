<?php

namespace App\Discussion\Values;

/** What a question can compare or split by. */
enum DimensionType: string
{
    case ProjectTag = 'project_tag';
    case PersonaTag = 'persona_tag';
    case Pipeline = 'pipeline';
    case Model = 'model';

    public function source(): DimensionSource
    {
        return match ($this) {
            self::ProjectTag => DimensionSource::ProjectTag,
            self::PersonaTag => DimensionSource::PersonaTag,
            self::Pipeline, self::Model => DimensionSource::Run,
        };
    }

    public function isTag(): bool
    {
        return $this === self::ProjectTag || $this === self::PersonaTag;
    }
}
