<?php

namespace App\Discussion\Values;

/** The shapes an answer can take. Which ones a question allows is Question::supports()' call. */
enum ResultForm: string
{
    case Points = 'points';
    case Composition = 'composition';
    case Trajectory = 'trajectory';

    public function label(): string
    {
        return __("questions.forms.{$this->value}");
    }
}
