<?php

namespace App\Discussion\Metrics\Templates;

use App\Discussion\Metrics\References\EachReference;
use App\Discussion\Metrics\References\PersonaTagsReference;
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
 * The share templates compare within the status projects: the title holder
 * (persona tag `title-holder: true`) against the others of the same runs
 * (`title-holder: false`, their summed share). Only the inequality template
 * compares the two conditions, since Gini describes a whole run. The control
 * comparison of one persona with and without its title is no template: it is
 * built by hand with "One expert by name". All templates count the first 60
 * turns, so the 100-turn runs stay comparable with the 60-turn cells.
 */
final class ComparisonTemplates
{
    /** The five pipelines of the study, by their stored short class name. */
    public const PIPELINES = ['ScorePipeline', 'OrchestratorPipeline', 'QualityPipeline', 'RoundRobinPipeline', 'BlindQualityPipeline'];

    /** The project category the conditions are tagged with, and its two values. */
    public const CONDITION = 'status condition';

    public const CONDITIONS = ['status', 'no-status'];

    /** The persona category that marks the titled agent, and its two values: the title holder, then the others. */
    public const TITLE_CATEGORY = 'title-holder';

    public const TITLE_VALUES = ['true', 'false'];

    public const TURN_LIMIT = 60;

    /** @return array<string, ComparisonTemplate> keyed by template key, in menu order */
    public function all(): array
    {
        $byPipeline = $this->byPipelineAndTitle(self::PIPELINES);
        $overall = $this->byTitle();

        $templates = [
            new ComparisonTemplate('turn_share_by_pipeline', $byPipeline, $this->view(Metric::TurnShare, ComparisonDisplay::Bars)),
            new ComparisonTemplate('word_share_by_pipeline', $byPipeline, $this->view(Metric::WordShare, ComparisonDisplay::Bars)),
            new ComparisonTemplate('inequality_by_pipeline', $this->byPipelineAndCondition(self::PIPELINES), $this->view(Metric::TurnGini, ComparisonDisplay::Bars)),
            new ComparisonTemplate('turn_share_overall', $overall, $this->view(Metric::TurnShare, ComparisonDisplay::Bars)),
            new ComparisonTemplate('word_share_overall', $overall, $this->view(Metric::WordShare, ComparisonDisplay::Bars)),
            new ComparisonTemplate('blind_vs_seeing_judge', $this->byPipelineAndTitle(['QualityPipeline', 'BlindQualityPipeline']), $this->view(Metric::TurnShare, ComparisonDisplay::Bars)),
        ];

        return array_column(array_map(fn (ComparisonTemplate $t) => [$t->key, $t], $templates), 1, 0);
    }

    public function find(string $key): ?ComparisonTemplate
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * The template's series as a comparison stores them: tag names resolved
     * to ids of this database (ignoring case) — project tags in the project
     * filter, persona tags in a persona_tags reference — then normalised like
     * any form input. A name this database does not know becomes an
     * unresolved tag of its series, which then matches no run until the user
     * removes it; the reference left without it matches nobody.
     *
     * @return list<array<string, mixed>>
     */
    public function seriesOf(ComparisonTemplate $template): array
    {
        $projectCategories = TagCategory::ofScope(TagScope::Project)->with('values')->get();
        $personaCategories = TagCategory::ofScope(TagScope::Persona)->with('values')->get();

        return array_map(function (array $series) use ($projectCategories, $personaCategories) {
            [$series['projects']['tags'], $unresolved] = $this->resolve($projectCategories, $series['projects']['tags'] ?? []);

            if (($series['reference']['type'] ?? null) === PersonaTagsReference::TYPE) {
                [$series['reference']['tags'], $unresolvedPersona] = $this->resolve($personaCategories, $series['reference']['tags'] ?? []);
                $unresolved = [...$unresolved, ...$unresolvedPersona];
            }

            $series['unresolved_tags'] = $unresolved;

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
     * "<Pipeline> · title holder" and "<Pipeline> · others" for each pipeline,
     * over its status projects. The pair shares its pipeline's hue, so it
     * reads as a pair: the title holder in the full shade, the others in the
     * light one.
     *
     * @param  list<string>  $pipelines
     * @return list<array<string, mixed>>
     */
    private function byPipelineAndTitle(array $pipelines): array
    {
        $series = [];

        foreach ($pipelines as $pipeline) {
            foreach (self::TITLE_VALUES as $index => $title) {
                $series[] = $this->titleSeries(
                    __('comparisons.templates.series', ['pipeline' => $this->pipelineLabel($pipeline), 'part' => $this->titleLabel($title)]),
                    $title,
                    $this->pairColor($this->hueOf($pipeline), $index),
                    $pipeline,
                );
            }
        }

        return $series;
    }

    /**
     * The title holder against the others across every pipeline.
     *
     * @return list<array<string, mixed>>
     */
    private function byTitle(): array
    {
        return array_map(
            fn (string $title, int $index) => $this->titleSeries($this->titleLabel($title), $title, $this->pairColor(SeriesColor::HUES[0], $index)),
            self::TITLE_VALUES,
            array_keys(self::TITLE_VALUES),
        );
    }

    /** @return array<string, mixed> one series over the status projects, looking at the agents with this title value */
    private function titleSeries(string $label, string $title, SeriesColor $color, ?string $pipeline = null): array
    {
        return [
            'label' => $label,
            'projects' => ['tags' => [self::CONDITION => [self::CONDITIONS[0]]], 'pipeline' => $pipeline],
            'reference' => ['type' => PersonaTagsReference::TYPE, 'tags' => [self::TITLE_CATEGORY => [$title]]],
            'color' => (string) $color,
        ];
    }

    /**
     * "<Pipeline> · status" and "<Pipeline> · no-status" for each pipeline.
     * A run-level measure needs no reference; the pair shares its pipeline's
     * hue, status in the full shade.
     *
     * @param  list<string>  $pipelines
     * @return list<array<string, mixed>>
     */
    private function byPipelineAndCondition(array $pipelines): array
    {
        $series = [];

        foreach ($pipelines as $pipeline) {
            foreach (self::CONDITIONS as $index => $condition) {
                $series[] = [
                    'label' => __('comparisons.templates.series', ['pipeline' => $this->pipelineLabel($pipeline), 'part' => $condition]),
                    'projects' => ['tags' => [self::CONDITION => [$condition]], 'pipeline' => $pipeline],
                    'reference' => (new EachReference)->toArray(),
                    'color' => (string) $this->pairColor($this->hueOf($pipeline), $index),
                ];
            }
        }

        return $series;
    }

    /** The first of a pair in the hue's full shade, the second in its light one. */
    private function pairColor(string $hue, int $index): SeriesColor
    {
        return SeriesColor::of($hue, $index === 0 ? SeriesColor::FULL : SeriesColor::LIGHT);
    }

    private function pipelineLabel(string $pipeline): string
    {
        return __("comparisons.templates.pipelines.{$pipeline}");
    }

    private function titleLabel(string $title): string
    {
        return __("comparisons.templates.titles.{$title}");
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
