<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Metrics\References\PersonaTagsReference;
use App\Discussion\Values\ComparisonDisplay;
use App\Discussion\Values\ComparisonView;
use App\Discussion\Values\Metric;
use App\Discussion\Values\SeriesDefinition;
use App\Discussion\Values\TagFilter;
use PHPUnit\Framework\TestCase;

class SeriesDefinitionTest extends TestCase
{
    public function test_form_input_is_normalised_and_round_trips(): void
    {
        $series = SeriesDefinition::fromArray([
            'label' => '  Titelträger ',
            'projects' => ['tags' => ['2' => ['5', '6']], 'pipeline' => '', 'model' => 'openai-gpt-5-mini'],
            'reference' => ['type' => 'persona_tags', 'tags' => ['9' => ['11']]],
        ]);

        $this->assertSame([
            'label' => 'Titelträger',
            'projects' => ['tags' => [2 => [5, 6]], 'pipeline' => null, 'model' => 'openai-gpt-5-mini'],
            'reference' => ['type' => 'persona_tags', 'tags' => [9 => [11]]],
        ], $series->toArray());
        $this->assertInstanceOf(PersonaTagsReference::class, $series->reference);
        $this->assertEqualsCanonicalizing([5, 6, 11], $series->tagValueIds());
    }

    public function test_deleted_tag_values_drop_out_of_project_and_persona_filters(): void
    {
        $series = SeriesDefinition::fromArray([
            'label' => 'x',
            'projects' => ['tags' => [2 => [5, 6]]],
            'reference' => ['type' => 'persona_tags', 'tags' => [9 => [11]]],
        ])->withoutTagValues([6, 11]);

        $this->assertSame([2 => [5]], $series->projectTags->toArray());
        // Emptied, the persona category would stop filtering; it keeps its dead id instead.
        $this->assertSame([11], $series->reference->tagValueIds());
    }

    public function test_a_category_whose_values_all_vanished_stays_unsatisfiable(): void
    {
        $filter = TagFilter::fromArray([2 => [5, 6], 3 => [7]])->without([7]);

        $this->assertSame([2 => [5, 6], 3 => [7]], $filter->toArray());
        $this->assertTrue($filter->isUnsatisfiable([5, 6]));
        $this->assertFalse($filter->isUnsatisfiable([5, 7]));
        $this->assertFalse((new TagFilter)->isUnsatisfiable([]));
    }

    public function test_a_broken_view_falls_back_to_the_defaults(): void
    {
        $view = ComparisonView::fromArray(['metric' => 'nope', 'display' => 'bars', 'turn_limit' => '0', 'trajectory_mode' => 'sideways']);

        $this->assertSame(Metric::TurnShare, $view->metric);
        $this->assertSame(ComparisonDisplay::Bars, $view->display);
        $this->assertNull($view->turnLimit);
        $this->assertSame('cumulative', $view->trajectoryMode);
        $this->assertSame(['metric' => 'turn_share', 'display' => 'bars', 'turn_limit' => null, 'trajectory_mode' => 'cumulative'], $view->toArray());
        $this->assertSame(60, ComparisonView::fromArray(['turn_limit' => '60'])->turnLimit);
    }

    public function test_a_pie_falls_back_to_the_table_for_a_metric_without_parts_of_a_whole(): void
    {
        $this->assertSame(ComparisonDisplay::Pie, ComparisonView::fromArray(['metric' => 'word_share', 'display' => 'pie'])->display);

        foreach (['words_per_turn', 'turn_gini', 'word_gini'] as $metric) {
            $this->assertSame(ComparisonDisplay::Table, ComparisonView::fromArray(['metric' => $metric, 'display' => 'pie'])->display, $metric);
        }
    }
}
