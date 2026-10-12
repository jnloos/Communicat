<?php

namespace App\Discussion\Values;

/** What a question can compare or split by. */
enum DimensionType: string
{
    case ProjectTag = 'project_tag';
    case PersonaTag = 'persona_tag';
    case Pipeline = 'pipeline';
    case Model = 'model';
    case Group = 'group';

    public function source(): DimensionSource
    {
        return match ($this) {
            self::ProjectTag => DimensionSource::ProjectTag,
            self::PersonaTag => DimensionSource::PersonaTag,
            self::Pipeline, self::Model => DimensionSource::Run,
            self::Group => DimensionSource::Group,
        };
    }

    public function isTag(): bool
    {
        return $this === self::ProjectTag || $this === self::PersonaTag;
    }
}
