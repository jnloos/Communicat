<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Metrics\Questions\DimensionValues;
use App\Discussion\Metrics\Questions\QuestionEvaluator;
use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\Question;
use App\Discussion\Values\QuestionResult;
use App\Discussion\Values\TurnRange;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QuestionEvaluator on synthetic runs whose answer can be worked out by hand.
 * It adds nothing to the counting rules; what it adds — panels, groups, per
 * head, fair share, dropped runs, curves — is what these cases pin down.
 */
class QuestionEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    /** @var array<string, Expert> */
    private array $experts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * @param  list<string>  $seats  names in seat order
     * @param  list<array{0: string, 1: int}>  $turns  [name, words] in turn order
     * @param  array<string, mixed>  $attributes
     */
    private function makeRun(array $seats, array $turns, array $attributes = []): Project
    {
        $project = Project::factory()->create(['user_id' => $this->user->id, ...$attributes]);

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

    /** @param  array<string, mixed>  $question */
    private function answer(array $question): QuestionResult
    {
        return app(QuestionEvaluator::class)->evaluate($this->user, Question::fromArray($question));
    }

    public function test_everyone_together_is_the_whole_run(): void
    {
        $this->makeRun(['A', 'B'], [['A', 10], ['A', 10], ['B', 10]]);

        $group = $this->answer([])->panels[0]->groups[0];

        $this->assertSame(1.0, $group->mean());
        $this->assertSame(1.0, $group->fairShare());
        $this->assertSame(1, $group->n());
    }

    public function test_a_persona_tag_compares_agents_of_the_same_run(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'rolle']);
        $titled = $category->values()->create(['name' => 'titel']);
        $rest = $category->values()->create(['name' => 'rest']);
        $this->makeRun(['A', 'B', 'C', 'D'], [['A', 10], ['A', 10], ['B', 10], ['C', 10]]);
        $this->experts['A']->setTag($category, $titled);

        foreach (['B', 'C', 'D'] as $name) {
            $this->experts[$name]->setTag($category, $rest);
        }

        $together = $this->answer(['compare_by' => "persona_tag:{$category->id}"])->panels[0];
        $perHead = $this->answer(['compare_by' => "persona_tag:{$category->id}", 'aggregate' => 'per_head'])->panels[0];

        $this->assertSame(['Rolle: titel', 'Rolle: rest'], array_map(fn ($g) => $g->label, $together->groups));
        $this->assertSame([0.5, 0.5], array_map(fn ($g) => $g->mean(), $together->groups));
        $this->assertSame([0.25, 0.75], array_map(fn ($g) => $g->fairShare(), $together->groups));
        $this->assertEqualsWithDelta(0.5 / 3, $perHead->groups[1]->mean(), 1e-9);
        $this->assertSame(0.25, $perHead->groups[1]->fairShare());
    }

    public function test_agents_without_a_value_form_their_own_group(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'gender']);
        $f = $category->values()->create(['name' => 'f']);
        $this->makeRun(['A', 'B'], [['A', 10], ['B', 10], ['B', 10]]);
        $this->experts['A']->setTag($category, $f);

        $groups = $this->answer(['compare_by' => "persona_tag:{$category->id}"])->panels[0]->groups;

        $this->assertSame([(string) $f->id, DimensionValues::NONE], array_map(fn ($g) => $g->key, $groups));
        $this->assertSame(['Gender: f', 'Gender: '.__('questions.result.no_value')], array_map(fn ($g) => $g->label, $groups));
        $this->assertEqualsWithDelta(1.0, $groups[0]->mean() + $groups[1]->mean(), 1e-9);
    }

    public function test_a_project_tag_compares_runs_and_gives_a_delta(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
        $without = $category->values()->create(['name' => 'ohne']);
        $with = $category->values()->create(['name' => 'mit']);
        $this->makeRun(['A', 'B'], [['A', 10], ['B', 10], ['A', 10], ['B', 10]])->setTag($category, $without);
        $this->makeRun(['A', 'B'], [['A', 10], ['A', 10], ['A', 10], ['B', 10]])->setTag($category, $with);

        $panel = $this->answer(['people' => ['expert_name' => 'A'], 'compare_by' => "project_tag:{$category->id}"])->panels[0];

        $this->assertSame(['Bedingung: ohne', 'Bedingung: mit'], array_map(fn ($g) => $g->label, $panel->groups));
        $this->assertSame(0.25, $panel->delta());
    }

    public function test_a_group_missing_from_a_run_counts_as_zero_so_the_shares_fill_the_panel(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'gender']);
        $f = $category->values()->create(['name' => 'f']);
        // Run 1: A is tagged, B is not. Run 2: nobody is tagged, so the tagged group is absent.
        $this->makeRun(['A', 'B'], [['A', 10], ['B', 10]]);
        $this->makeRun(['C', 'D'], [['C', 10], ['D', 10], ['D', 10]]);
        $this->experts['A']->setTag($category, $f);

        $panel = $this->answer(['compare_by' => "persona_tag:{$category->id}"])->panels[0];
        $sum = array_sum(array_map(fn ($group) => $panel->shareOf($group), $panel->groups));

        $this->assertSame(2, $panel->runs);
        $this->assertEqualsWithDelta(0.25, $panel->shareOf($panel->groups[0]), 1e-9);
        $this->assertEqualsWithDelta(1.0, $sum, 1e-9);
    }

    public function test_a_delta_needs_the_whole_answer_to_compare_two_groups(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
        $a = $category->values()->create(['name' => 'a']);
        $b = $category->values()->create(['name' => 'b']);
        $c = $category->values()->create(['name' => 'c']);
        $this->makeRun(['A'], [['A', 10]], ['pipeline' => 'ScorePipeline'])->setTag($category, $a);
        $this->makeRun(['A'], [['A', 10]], ['pipeline' => 'ScorePipeline'])->setTag($category, $b);
        $this->makeRun(['A'], [['A', 10]], ['pipeline' => 'RoundRobinPipeline'])->setTag($category, $c);

        $panels = $this->answer(['compare_by' => "project_tag:{$category->id}", 'split_by' => 'pipeline'])->panels;
        $score = collect($panels)->firstWhere('key', 'ScorePipeline');

        $this->assertCount(2, $score->groups);
        $this->assertNull($score->delta());
    }

    public function test_a_per_head_curve_is_the_group_curve_divided_by_its_size(): void
    {
        $category = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'rolle']);
        $x = $category->values()->create(['name' => 'x']);
        $this->makeRun(['A', 'B', 'C'], [['A', 10], ['B', 10], ['C', 10], ['A', 10]]);
        $this->experts['A']->setTag($category, $x);
        $this->experts['B']->setTag($category, $x);

        $base = ['compare_by' => "persona_tag:{$category->id}", 'form' => 'trajectory', 'curve' => 'cumulative'];
        $together = $this->answer($base)->panels[0]->groups[0]->curve;
        $perHead = $this->answer([...$base, 'aggregate' => 'per_head'])->panels[0]->groups[0]->curve;

        $this->assertNotEmpty($together);
        $this->assertCount(count($together), $perHead);

        foreach ($together as $i => $point) {
            $this->assertEqualsWithDelta($point->mean / 2, $perHead[$i]->mean, 1e-9);
        }
        $this->assertEqualsWithDelta(0.75, end($together)->mean, 1e-9);
    }

    public function test_split_by_pipeline_gives_panels_in_registry_order(): void
    {
        $this->makeRun(['A'], [['A', 10]], ['pipeline' => 'ScorePipeline']);
        $this->makeRun(['A'], [['A', 10]], ['pipeline' => 'RoundRobinPipeline']);

        $keys = array_map(fn ($panel) => $panel->key, $this->answer(['split_by' => 'pipeline'])->panels);
        $expected = array_values(array_intersect(array_keys(app(PipelineRegistry::class)->options()), ['ScorePipeline', 'RoundRobinPipeline']));

        $this->assertSame($expected, $keys);
    }

    public function test_runs_without_an_answer_drop_out_and_are_counted(): void
    {
        $this->makeRun(['A', 'B'], []);
        $this->makeRun(['A', 'B'], [['A', 10]]);

        $result = $this->answer(['people' => ['expert_name' => 'Nobody']]);

        $this->assertTrue($result->isEmpty());
        $this->assertSame(2, $result->runs);
        $this->assertSame(2, $result->excluded);
    }

    public function test_gini_describes_the_whole_run(): void
    {
        $this->makeRun(['A', 'B'], [['A', 10], ['A', 10]]);

        $group = $this->answer(['metric' => 'turn_gini', 'people' => ['expert_name' => 'B']])->panels[0]->groups[0];

        $this->assertSame(0.5, $group->mean());
        $this->assertNull($group->fairShare());
    }

    public function test_the_curve_is_the_mean_over_the_runs_at_each_turn_of_the_shared_window(): void
    {
        $this->makeRun(['A', 'B'], [['A', 10], ['B', 10], ['B', 10]]);
        $this->makeRun(['A', 'B'], [['B', 10], ['B', 10], ['A', 10]]);

        $curve = $this->answer(['people' => ['expert_name' => 'A'], 'form' => 'trajectory'])->panels[0]->groups[0]->curve;

        $this->assertSame([1, 2, 3], array_map(fn ($point) => $point->turn, $curve));
        // Turn 1: A has 1 in the first run, 0 in the second. Turn 2: 1/2 and 0. Turn 3: 1/3 and 1/3.
        $this->assertEqualsWithDelta([0.5, 0.25, 1 / 3], array_map(fn ($point) => $point->mean, $curve), 1e-9);
        $this->assertSame([2, 2, 2], array_map(fn ($point) => $point->n, $curve));
    }

    public function test_another_users_runs_are_never_read(): void
    {
        Project::factory()->create();

        $this->assertSame(0, $this->answer([])->runs);
    }

    public function test_every_run_is_read_over_the_turns_of_the_shortest_one(): void
    {
        $this->makeRun(['A', 'B'], [['A', 10], ['A', 10], ['A', 10]]);
        $this->makeRun(['A', 'B'], [['A', 10], ['A', 10], ['A', 10], ['B', 10], ['B', 10]]);

        $result = $this->answer(['people' => ['expert_name' => 'B']]);

        $this->assertSame(3, $result->turns->to);
        $this->assertTrue($result->cappedByShortestRun);
        $this->assertSame(0.0, $result->panels[0]->groups[0]->mean(), 'B speaks only after turn 3, outside the shared window');
        $this->assertSame(2, $result->panels[0]->groups[0]->n());
    }

    public function test_a_given_upper_bound_below_the_shortest_run_is_kept_and_not_capped(): void
    {
        $this->makeRun(['A', 'B'], [['A', 10], ['B', 10], ['A', 10]]);
        $this->makeRun(['A', 'B'], [['A', 10], ['B', 10], ['A', 10], ['A', 10], ['A', 10]]);

        $result = $this->answer(['turns' => ['to' => 2], 'people' => ['expert_name' => 'A']]);

        $this->assertEquals(new TurnRange(1, 2), $result->turns);
        $this->assertFalse($result->cappedByShortestRun);
        $this->assertSame(0.5, $result->panels[0]->groups[0]->mean());
    }

    public function test_an_upper_bound_above_the_shortest_run_is_pulled_down_to_it(): void
    {
        $this->makeRun(['A'], [['A', 10], ['A', 10]]);
        $this->makeRun(['A'], [['A', 10], ['A', 10], ['A', 10], ['A', 10]]);

        $result = $this->answer(['turns' => ['to' => 4]]);

        $this->assertSame(2, $result->turns->to);
        $this->assertTrue($result->cappedByShortestRun);
    }

    public function test_a_window_that_starts_after_the_shortest_run_excludes_every_run(): void
    {
        $this->makeRun(['A'], [['A', 10], ['A', 10], ['A', 10]]);
        $this->makeRun(['A'], [['A', 10], ['A', 10], ['A', 10], ['A', 10], ['A', 10]]);

        $result = $this->answer(['turns' => ['from' => 4]]);

        $this->assertTrue($result->isEmpty());
        $this->assertSame(2, $result->runs);
        $this->assertSame(2, $result->excluded);
        $this->assertSame(3, $result->shortestRun);
        $this->assertSame(4, $result->turns->to, 'never below the lower bound');
    }

    public function test_a_run_the_people_filter_drops_does_not_shorten_the_window_of_the_others(): void
    {
        $this->makeRun(['A'], [['A', 10], ['A', 10]]);
        $this->makeRun(['B'], [['B', 10], ['B', 10], ['B', 10], ['B', 10]]);

        $result = $this->answer(['people' => ['expert_name' => 'B']]);

        $this->assertSame(4, $result->shortestRun);
        $this->assertSame(4, $result->turns->to);
        $this->assertSame(1, $result->excluded);
    }

    public function test_a_run_without_a_spoken_turn_does_not_set_the_shortest_length(): void
    {
        $this->makeRun(['A'], []);
        $this->makeRun(['A'], [['A', 10], ['A', 10], ['A', 10]]);

        $result = $this->answer([]);

        $this->assertSame(3, $result->turns->to);
        $this->assertSame(1, $result->excluded);
    }

    public function test_with_no_run_the_window_stays_as_asked_and_is_not_capped(): void
    {
        $result = $this->answer(['turns' => ['from' => 2]]);

        $this->assertEquals(new TurnRange(2), $result->turns);
        $this->assertFalse($result->cappedByShortestRun);
    }

    public function test_the_curve_runs_over_the_window_with_its_real_turn_numbers(): void
    {
        $this->makeRun(['A', 'B'], [['A', 10], ['A', 10], ['B', 10], ['B', 10]]);

        $group = $this->answer(['form' => 'trajectory', 'turns' => ['from' => 3]])->panels[0]->groups[0];

        $this->assertSame([3, 4], array_map(fn ($mean) => $mean->turn, $group->curve));
    }
}
