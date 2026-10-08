<?php

namespace App\Livewire\Statistics;

use App\Discussion\Metrics\References\AgentReference;
use App\Discussion\Metrics\References\ExpertNameReference;
use App\Discussion\Metrics\References\PersonaTagsReference;
use App\Discussion\Values\SeriesDefinition;
use App\Discussion\Values\TagFilter;

/**
 * A series in one line, for the header of its folded card:
 * "Status condition: status · Bidding · any model · Experts by persona tag: Title-holder: true · 6 projects".
 * It names the filter as the form shows it and computes nothing.
 */
final class SeriesSummary
{
    /**
     * @param  array<int, string>  $tagLabels  tag value id => "Category: value"
     * @param  array<string, string>  $pipelines  stored name => label
     * @param  array<string, string>  $models  key => label
     */
    public function __construct(
        private readonly array $tagLabels,
        private readonly array $pipelines,
        private readonly array $models,
    ) {}

    public function of(SeriesDefinition $series, int $matches): string
    {
        return implode(' · ', array_filter([
            $this->tags($series->projectTags, $series->unresolvedLabels()),
            $series->pipeline === null ? __('comparisons.series.any_pipeline') : ($this->pipelines[$series->pipeline] ?? $series->pipeline),
            $series->model === null ? __('comparisons.series.any_model') : ($this->models[$series->model] ?? $series->model),
            $this->reference($series->reference),
            trans_choice('comparisons.series.summary_projects', $matches, ['count' => $matches]),
        ], fn (string $part) => $part !== ''));
    }

    /** @param  list<string>  $extra  labels without an id (a template's unknown tags) */
    private function tags(TagFilter $filter, array $extra = []): string
    {
        $labels = array_map(fn (int $id) => $this->tagLabels[$id] ?? '', $filter->valueIds());

        return implode(', ', array_filter([...$labels, ...$extra]));
    }

    private function reference(AgentReference $reference): string
    {
        return match (true) {
            $reference instanceof ExpertNameReference && $reference->name !== '' => $reference->name,
            $reference instanceof PersonaTagsReference && ! $reference->tags->isEmpty() => __('comparisons.reference.types.persona_tags').': '.$this->tags($reference->tags),
            default => __('comparisons.reference.types.'.$reference->toArray()['type']),
        };
    }
}
