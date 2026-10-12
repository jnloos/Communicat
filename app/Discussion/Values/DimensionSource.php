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

    /** A listed name with its source in square brackets: "Pipeline [Run]". */
    public function named(string $name): string
    {
        return __('questions.source_label', ['name' => $name, 'source' => $this->label()]);
    }
}
