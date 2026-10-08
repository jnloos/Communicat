<?php

namespace App\Discussion\Metrics\Templates;

use App\Discussion\Metrics\References\AgentReference;
use App\Discussion\Metrics\References\EachReference;
use App\Discussion\Metrics\References\PersonaTagsReference;
use App\Discussion\Metrics\References\SeatReference;
use App\Discussion\Values\ComparisonDisplay;
use App\Discussion\Values\ComparisonView;
use App\Discussion\Values\Metric;
use App\Discussion\Values\SeriesDefinition;
use App\Models\TagCategory;
use App\Models\TagScope;

/**
 * The thesis's standard analyses: does a status title raise the speaking share
 * of its holder, and does that depend on the speaker-selection mechanism?
 *
 * The focal agent is seat 1 in both conditions (status: the titled agent;
 * control: the same position), the convention of every report so far. All
 * templates count the first 60 turns, so the 100-turn runs stay comparable
 * with the 60-turn cells.
 */
final class ComparisonTemplates
{
    /** The five pipelines of the study, by their stored short class name. */
    public const PIPELINES = ['ScorePipeline', 'OrchestratorPipeline', 'QualityPipeline', 'RoundRobinPipeline', 'BlindQualityPipeline'];

    /** The project category the conditions are tagged with, and its two values. */
    public const CONDITION = 'condition';

    public const CONDITIONS = ['status', 'control'];

    public const TURN_LIMIT = 60;

    /**
     * Stand-in for a category or value name this database does not know: id 0
     * never exists, so the series matches nothing instead of silently
     * dropping the filter and widening to every run.
     */
    private const UNKNOWN = 0;

    /** @return array<string, ComparisonTemplate> keyed by template key, in menu order */
    public function all(): array
    {
        $byPipeline = $this->byPipelineAndCondition(self::PIPELINES);

        $templates = [
            new ComparisonTemplate('turn_share_by_pipeline', $byPipeline, $this->view(Metric::TurnShare, ComparisonDisplay::Bars)),
            new ComparisonTemplate('word_share_by_pipeline', $byPipeline, $this->view(Metric::WordShare, ComparisonDisplay::Bars)),
            new ComparisonTemplate('inequality_by_pipeline', $byPipeline, $this->view(Metric::TurnGini, ComparisonDisplay::Bars)),
            new ComparisonTemplate('words_per_turn_seat_one', $byPipeline, $this->view(Metric::WordsPerTurn, ComparisonDisplay::Bars)),
            new ComparisonTemplate('seat_one_over_the_run', $this->byCondition(new SeatReference(1)), $this->view(Metric::TurnShare, ComparisonDisplay::Trajectory)),
            new ComparisonTemplate('blind_vs_seeing_judge', $this->byPipelineAndCondition(['QualityPipeline', 'BlindQualityPipeline']), $this->view(Metric::TurnShare, ComparisonDisplay::Bars)),
            new ComparisonTemplate('every_seat_by_condition', $this->byCondition(new EachReference), $this->view(Metric::TurnShare, ComparisonDisplay::Pie)),
        ];

        return array_column(array_map(fn (ComparisonTemplate $t) => [$t->key, $t], $templates), 1, 0);
    }

    public function find(string $key): ?ComparisonTemplate
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * The template's series as a comparison stores them: tag names resolved
     * to ids of this database, then normalised like any form input.
     *
     * @return list<array<string, mixed>>
     */
    public function seriesOf(ComparisonTemplate $template): array
    {
        return array_map(function (array $series) {
            $series['projects']['tags'] = $this->idsFor(TagScope::Project, $series['projects']['tags'] ?? []);

            if (($series['reference']['type'] ?? null) === PersonaTagsReference::TYPE) {
                $series['reference']['tags'] = $this->idsFor(TagScope::Persona, $series['reference']['tags'] ?? []);
            }

            return SeriesDefinition::fromArray($series)->toArray();
        }, $template->series);
    }

    /**
     * Category name => value names, as category id => value ids. Unknown
     * names never widen the filter: a category none of whose values exists
     * stays in it with the id 0, which matches nothing.
     *
     * @param  array<string, list<string>>  $names
     * @return array<int, list<int>>
     */
    private function idsFor(TagScope $scope, array $names): array
    {
        $categories = TagCategory::ofScope($scope)->with('values')->get();
        $ids = [];

        foreach ($names as $categoryName => $valueNames) {
            $category = $categories->firstWhere('name', $categoryName);
            $valueIds = $category === null ? [] : $category->values->whereIn('name', $valueNames)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $categoryId = $category === null ? self::UNKNOWN : $category->id;

            $ids[$categoryId] = $valueIds === [] ? [self::UNKNOWN] : array_values($valueIds);
        }

        return $ids;
    }

    /**
     * "<Pipeline> · status" and "<Pipeline> · control" for each pipeline, seat 1.
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
                ];
            }
        }

        return $series;
    }

    /**
     * Status against control across every pipeline.
     *
     * @return list<array<string, mixed>>
     */
    private function byCondition(AgentReference $reference): array
    {
        return array_map(fn (string $condition) => [
            'label' => $condition,
            'projects' => ['tags' => [self::CONDITION => [$condition]]],
            'reference' => $reference->toArray(),
        ], self::CONDITIONS);
    }

    private function view(Metric $metric, ComparisonDisplay $display): ComparisonView
    {
        return new ComparisonView(metric: $metric, display: $display, turnLimit: self::TURN_LIMIT);
    }
}
