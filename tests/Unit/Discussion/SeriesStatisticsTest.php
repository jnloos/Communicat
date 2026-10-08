<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Metrics\SeriesStatistics;
use App\Discussion\Values\ComparisonView;
use App\Discussion\Values\SeriesDefinition;
use App\Discussion\Values\SeriesResult;
use App\Discussion\Values\TrajectoryMean;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SeriesStatistics on synthetic extreme cases whose answer can be worked out by
 * hand. It adds nothing to the counting rules; what it adds — combining agents,
 * dropping runs, cutting at a turn, averaging curves of unequal length — is
 * exactly what these cases pin down.
 */
class SeriesStatisticsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, Expert> */
    private array $experts = [];

    /**
     * @param  list<string>  $seats  names in seat order
     * @param  list<array{0: string, 1: int}>  $turns  [name, words] in turn order
     */
    private function makeRun(array $seats, array $turns): Project
    {
        $project = Project::factory()->create();

        foreach ($seats as $name) {
            $project->addContributingExpert($this->experts[$name] ??= Expert::factory()->create(['name' => $name]));
        }

        foreach ($turns as $index => [$name, $words]) {
            JobLog::create([
                'job_class' => 'App\Discussion\TurnRunner',
                'project_id' => $project->id,
                'status' => 'success',
                'started_at' => now(),
                'turn_index' => $index + 1,
                'expert_id' => $this->experts[$name]->id,
                'words' => $words,
            ]);
        }

        return $project->fresh();
    }

    private function compute(array $reference, array $view, Project ...$projects): SeriesResult
    {
        return app(SeriesStatistics::class)->compute(
            SeriesDefinition::fromArray(['label' => 'Reihe', 'reference' => $reference]),
            ComparisonView::fromArray($view),
            collect($projects),
        );
    }

    public function test_several_agents_count_with_their_combined_share(): void
    {
        $tag = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'gruppe']);
        $minority = $tag->values()->create(['name' => 'minderheit']);
        $project = $this->makeRun(['Alice', 'Bob', 'Carol', 'Dave'], [['Alice', 10], ['Alice', 10], ['Bob', 10], ['Carol', 10]]);
        $this->experts['Alice']->setTag($tag, $minority);
        $this->experts['Bob']->setTag($tag, $minority);

        $result = $this->compute(['type' => 'persona_tags', 'tags' => [$tag->id => [$minority->id]]], ['metric' => 'turn_share'], $project);

        $this->assertSame(0.75, $result->lines[0]->mean());
        $this->assertSame(0, $result->excluded);
    }

    public function test_a_run_where_the_reference_hits_nobody_drops_out_and_is_counted(): void
    {
        $project = $this->makeRun(['Alice', 'Bob'], [['Alice', 10]]);

        $result = $this->compute(['type' => 'seat', 'seat' => 9], ['metric' => 'turn_share'], $project);

        $this->assertSame([], $result->lines);
        $this->assertSame(1, $result->projects);
        $this->assertSame(1, $result->excluded);
    }

    public function test_a_run_without_a_spoken_turn_drops_out(): void
    {
        $result = $this->compute(['type' => 'seat', 'seat' => 1], ['metric' => 'turn_share'], $this->makeRun(['Alice'], []));

        $this->assertSame(1, $result->excluded);
    }

    public function test_the_turn_limit_cuts_every_run(): void
    {
        $project = $this->makeRun(['Alice', 'Bob'], [['Alice', 10], ['Alice', 10], ['Bob', 10], ['Bob', 10]]);

        $this->assertSame(1.0, $this->compute(['type' => 'seat', 'seat' => 1], ['metric' => 'turn_share', 'turn_limit' => 2], $project)->lines[0]->mean());
        $this->assertSame(0.5, $this->compute(['type' => 'seat', 'seat' => 1], ['metric' => 'turn_share'], $project)->lines[0]->mean());
    }

    public function test_mean_min_max_and_n_run_over_the_projects(): void
    {
        $a = $this->makeRun(['Alice', 'Bob'], [['Alice', 10], ['Bob', 10]]);
        $b = $this->makeRun(['Alice', 'Bob'], [['Alice', 10], ['Alice', 10], ['Alice', 10], ['Bob', 10]]);

        $line = $this->compute(['type' => 'seat', 'seat' => 1], ['metric' => 'turn_share'], $a, $b)->lines[0];

        $this->assertSame(2, $line->n());
        $this->assertSame(0.625, $line->mean());
        $this->assertSame(0.5, $line->min());
        $this->assertSame(0.75, $line->max());
    }

    public function test_curves_average_over_the_runs_that_reached_the_turn(): void
    {
        $short = $this->makeRun(['Alice', 'Bob'], [['Alice', 10], ['Bob', 10]]);
        $long = $this->makeRun(['Alice', 'Bob'], [['Alice', 10], ['Alice', 10], ['Alice', 10], ['Alice', 10]]);

        $curve = $this->compute(['type' => 'seat', 'seat' => 1], ['metric' => 'turn_share', 'display' => 'trajectory'], $short, $long)->lines[0]->curve;

        $this->assertEquals([
            new TrajectoryMean(1, 1.0, 2),
            new TrajectoryMean(2, 0.75, 2),
            new TrajectoryMean(3, 1.0, 1),
            new TrajectoryMean(4, 1.0, 1),
        ], $curve);
    }

    public function test_curves_are_only_drawn_for_the_trajectory_display(): void
    {
        $project = $this->makeRun(['Alice', 'Bob'], [['Alice', 10], ['Bob', 10]]);

        $this->assertSame([], $this->compute(['type' => 'seat', 'seat' => 1], ['metric' => 'turn_share', 'display' => 'table'], $project)->lines[0]->curve);
    }

    public function test_each_gives_one_line_per_seat_in_seat_order(): void
    {
        $project = $this->makeRun(['Alice', 'Bob', 'Carol'], [['Carol', 10], ['Alice', 10], ['Alice', 10], ['Bob', 10]]);

        $lines = $this->compute(['type' => 'each'], ['metric' => 'turn_share'], $project)->lines;

        $this->assertSame([1, 2, 3], array_map(fn ($line) => $line->seat, $lines));
        $this->assertSame([0.5, 0.25, 0.25], array_map(fn ($line) => $line->mean(), $lines));
    }

    public function test_gini_ignores_the_reference(): void
    {
        $project = $this->makeRun(['Alice', 'Bob'], [['Alice', 10], ['Alice', 10]]);

        $result = $this->compute(['type' => 'seat', 'seat' => 9], ['metric' => 'turn_gini'], $project);

        $this->assertSame(0, $result->excluded);
        $this->assertSame(0.5, round($result->lines[0]->mean(), 10));
    }

    public function test_words_per_turn_of_a_group_is_its_words_over_its_turns(): void
    {
        $tag = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'gruppe']);
        $both = $tag->values()->create(['name' => 'beide']);
        $project = $this->makeRun(['Alice', 'Bob'], [['Alice', 10], ['Bob', 30], ['Bob', 50]]);
        $this->experts['Alice']->setTag($tag, $both);
        $this->experts['Bob']->setTag($tag, $both);

        $result = $this->compute(['type' => 'persona_tags', 'tags' => [$tag->id => [$both->id]]], ['metric' => 'words_per_turn'], $project);

        $this->assertSame(30.0, $result->lines[0]->mean());
    }

    public function test_gini_with_the_top_reference_does_not_ask_the_reference(): void
    {
        $a = $this->makeRun(['Alice', 'Bob'], [['Alice', 10], ['Alice', 10]]);
        $b = $this->makeRun(['Alice', 'Bob'], [['Alice', 10], ['Bob', 10]]);

        $result = $this->compute(['type' => 'top'], ['metric' => 'turn_gini'], $a, $b);

        $this->assertSame(0, $result->excluded);
        $this->assertSame(2, $result->lines[0]->n());
        $this->assertSame([0.5, 0.0], array_map(fn ($v) => round($v->value, 10), $result->lines[0]->values));
    }
}
