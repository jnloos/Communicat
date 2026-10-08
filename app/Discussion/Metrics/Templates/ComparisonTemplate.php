<?php

namespace App\Discussion\Metrics\Templates;

use App\Discussion\Values\ComparisonView;

/**
 * A standard analysis of the thesis, ready to become a normal comparison. Its
 * series name tags by category and value name, not by id: ids differ between
 * databases, names are what the study agreed on. ComparisonTemplates resolves
 * them when the comparison is created.
 */
final readonly class ComparisonTemplate
{
    /**
     * Each series has the SeriesDefinition::toArray() shape, except that
     * `projects.tags` (and a persona_tags reference's `tags`) map category
     * names to value names.
     *
     * @param  list<array<string, mixed>>  $series
     */
    public function __construct(
        public string $key,
        public array $series,
        public ComparisonView $view,
    ) {}

    public function name(): string
    {
        return __("comparisons.templates.names.{$this->key}");
    }
}
