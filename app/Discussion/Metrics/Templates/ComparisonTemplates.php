<?php

namespace App\Discussion\Metrics\Templates;

use App\Discussion\Metrics\References\AgentReference;
use App\Discussion\Metrics\References\SeatReference;
use App\Discussion\Values\ComparisonDisplay;
use App\Discussion\Values\ComparisonView;
use App\Discussion\Values\Metric;
use App\Discussion\Values\SeriesColor;
use App\Discussion\Values\SeriesDefinition;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
use App\Services\Tags\TagNames;
use Illuminate\Support\Collection;

/**
 * The thesis's standard analyses: does a status title raise the speaking share
 * of its holder, and does that depend on the speaker-selection mechanism?
 *
 * The focal agent is seat 1 in both conditions (status: the titled agent;
 * no-status: the same position), the convention of every report so far. All
 * templates count the first 60 turns, so the 100-turn runs stay comparable
 * with the 60-turn cells.
 */
final class ComparisonTemplates
{
    /** The five pipelines of the study, by their stored short class name. */
    public const PIPELINES = ['ScorePipeline', 'OrchestratorPipeline', 'QualityPipeline', 'RoundRobinPipeline', 'BlindQualityPipeline'];

    /** The project category the conditions are tagged with, and its two values. */
    public const CONDITION = 'status condition';

    public const CONDITIONS = ['status', 'no-status'];

    public const TURN_LIMIT = 60;

    /** @return array<string, ComparisonTemplate> keyed by template key, in menu order */
    public function all(): array
    {
        $byPipeline = $this->byPipelineAndCondition(self::PIPELINES);
        $overall = $this->byCondition(new SeatReference(1));

        $templates = [
            new ComparisonTemplate('turn_share_by_pipeline', $byPipeline, $this->view(Metric::TurnShare, ComparisonDisplay::Bars)),
            new ComparisonTemplate('word_share_by_pipeline', $byPipeline, $this->view(Metric::WordShare, ComparisonDisplay::Bars)),
            new ComparisonTemplate('inequality_by_pipeline', $byPipeline, $this->view(Metric::TurnGini, ComparisonDisplay::Bars)),
            new ComparisonTemplate('turn_share_overall', $overall, $this->view(Metric::TurnShare, ComparisonDisplay::Bars)),
            new ComparisonTemplate('word_share_overall', $overall, $this->view(Metric::WordShare, ComparisonDisplay::Bars)),
            new ComparisonTemplate('blind_vs_seeing_judge', $this->byPipelineAndCondition(['QualityPipeline', 'BlindQualityPipeline']), $this->view(Metric::TurnShare, ComparisonDisplay::Bars)),
        ];

        return array_column(array_map(fn (ComparisonTemplate $t) => [$t->key, $t], $templates), 1, 0);
    }

    public function find(string $key): ?ComparisonTemplate
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * The template's series as a comparison stores them: tag names resolved
     * to ids of this database (ignoring case), then normalised like any form
     * input. A name this database does not know becomes an unresolved tag of
     * its series, which then matches no run until the user removes it.
     *
     * @return list<array<string, mixed>>
     */
    public function seriesOf(ComparisonTemplate $template): array
    {
        $categories = TagCategory::ofScope(TagScope::Project)->with('values')->get();

        return array_map(function (array $series) use ($categories) {
            [$series['projects']['tags'], $series['unresolved_tags']] = $this->resolve($categories, $series['projects']['tags'] ?? []);

            return SeriesDefinition::fromArray($series)->toArray();
        }, $template->series);
    }

    /**
     * Category name => value names, as category id => value ids, plus every
     * name pair that has no id here. Never a placeholder id: a value that
     * does not exist cannot sit in a filter, and dropping it silently would
     * widen the filter.
     *
     * @param  Collection<int, TagCategory>  $categories  of one scope, values loaded
     * @param  array<string, list<string>>  $names
     * @return array{0: array<int, list<int>>, 1: list<array{category: string, value: string}>}
     */
    private function resolve(Collection $categories, array $names): array
    {
        $ids = [];
        $unresolved = [];

        foreach ($names as $categoryName => $valueNames) {
            $category = $categories->first(fn (TagCategory $c) => TagNames::same($c->name, $categoryName));

            foreach ($valueNames as $valueName) {
                $value = $category?->values->first(fn (TagValue $v) => TagNames::same($v->name, $valueName));

                if ($value === null) {
                    $unresolved[] = ['category' => $category->name ?? $categoryName, 'value' => $valueName];
                } else {
                    $ids[$category->id][] = (int) $value->id;
                }
            }
        }

        return [$ids, $unresolved];
    }

    /**
     * "<Pipeline> · status" and "<Pipeline> · no-status" for each pipeline, seat 1.
     * The pair shares its pipeline's hue, so it reads as a pair: status in
     * the full shade, no-status in the light one.
     *
     * @param  list<string>  $pipelines
     * @return list<array<string, mixed>>
     */
    private function byPipelineAndCondition(array $pipelines): array
    {
        $series = [];

        foreach ($pipelines as $pipeline) {
            foreach (self::CONDITIONS as $condition) {
                $series[] = [
                    'label' => __('comparisons.templates.series', ['pipeline' => __("comparisons.templates.pipelines.{$pipeline}"), 'condition' => $condition]),
                    'projects' => ['tags' => [self::CONDITION => [$condition]], 'pipeline' => $pipeline],
                    'reference' => (new SeatReference(1))->toArray(),
                    'color' => (string) $this->colorFor($this->hueOf($pipeline), $condition),
                ];
            }
        }

        return $series;
    }

    /**
     * Status against no-status across every pipeline.
     *
     * @return list<array<string, mixed>>
     */
    private function byCondition(AgentReference $reference): array
    {
        return array_map(fn (string $condition) => [
            'label' => $condition,
            'projects' => ['tags' => [self::CONDITION => [$condition]]],
            'reference' => $reference->toArray(),
            'color' => (string) $this->colorFor(SeriesColor::HUES[0], $condition),
        ], self::CONDITIONS);
    }

    /** Status in the hue's full shade, no-status in its light one. */
    private function colorFor(string $hue, string $condition): SeriesColor
    {
        return SeriesColor::of($hue, $condition === self::CONDITIONS[0] ? SeriesColor::FULL : SeriesColor::LIGHT);
    }

    /** One hue per pipeline, the same in every template. */
    private function hueOf(string $pipeline): string
    {
        return SeriesColor::HUES[array_search($pipeline, self::PIPELINES, true)];
    }

    private function view(Metric $metric, ComparisonDisplay $display): ComparisonView
    {
        return new ComparisonView(metric: $metric, display: $display, turnLimit: self::TURN_LIMIT);
    }
}
