<?php

namespace App\Discussion\Values;

/** Where a listed item comes from, so the statistics page can say so next to it. */
enum DimensionSource: string
{
    case ProjectTag = 'project_tag';
    case PersonaTag = 'persona_tag';
    case Run = 'run';

    public function label(): string
    {
        return __("questions.sources.{$this->value}");
    }
}
