<?php

namespace App\Discussion\Values;

/** What a question can compare or split by. */
enum DimensionType: string
{
    case ProjectTag = 'project_tag';
    case PersonaTag = 'persona_tag';
    case Pipeline = 'pipeline';
    case Model = 'model';

    public function isTag(): bool
    {
        return $this === self::ProjectTag || $this === self::PersonaTag;
    }
}
