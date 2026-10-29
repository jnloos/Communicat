<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Metrics\Questions\DimensionValues;
use App\Discussion\Metrics\Questions\QuestionEvaluator;
use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\Dimension;
use App\Discussion\Values\QuestionDefinition;
use App\Discussion\Values\QuestionResult;
use App\Discussion\Values\TurnRange;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        return app(QuestionEvaluator::class)->evaluate($this->user, QuestionDefinition::fromArray($question));
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

    public function test_a_leaver_whose_tag_matches_the_people_filter_is_counted(): void
    {
        $focus = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'focus persona']);
        $true = $focus->values()->create(['name' => 'true']);
        $run = $this->makeRun(['A', 'B', 'C'], [['A', 10], ['A', 10], ['A', 10], ['B', 10]]);
        $this->experts['A']->setTag($focus, $true);
        $run->removeContributingExpert($this->experts['A']);

        $group = $this->answer(['people' => ['tags' => [$focus->id => [$true->id]]]])->panels[0]->groups[0];

        $this->assertSame(0.75, $group->mean());
        $this->assertSame(1, $group->n());
        // A, B and C are all participants of the run: the fair share of one of three.
        $this->assertEqualsWithDelta(1 / 3, $group->fairShare(), 1e-9);
    }

    public function test_a_leaver_whose_tag_does_not_match_the_people_filter_is_not_counted(): void
    {
        $focus = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'focus persona']);
        $true = $focus->values()->create(['name' => 'true']);
        $run = $this->makeRun(['A', 'B', 'C'], [['A', 10], ['A', 10], ['A', 10], ['B', 10]]);
        $this->experts['B']->setTag($focus, $true);
        $run->removeContributingExpert($this->experts['A']);

        $group = $this->answer(['people' => ['tags' => [$focus->id => [$true->id]]]])->panels[0]->groups[0];

        $this->assertSame(0.25, $group->mean());
        $this->assertEqualsWithDelta(1 / 3, $group->fairShare(), 1e-9);
    }

    public function test_persona_tags_of_leavers_are_read_ahead(): void
    {
        $focus = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'focus persona']);
        $true = $focus->values()->create(['name' => 'true']);
        $runs = [];

        foreach (range(1, 4) as $index) {
            $runs[] = $run = $this->makeRun(["A{$index}", "B{$index}"], [["A{$index}", 10], ["B{$index}", 10]]);
            $this->experts["A{$index}"]->setTag($focus, $true);
            $run->removeContributingExpert($this->experts["A{$index}"]);
        }

        $values = app(DimensionValues::class)->withRuns(new Collection($runs));
        DB::enableQueryLog();
        $values->ofExperts(Dimension::fromKey("persona_tag:{$focus->id}"), array_map(fn (int $i) => $this->experts["A{$i}"]->id, range(1, 4)));
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(0, $queries);
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

    public function test_two_splits_place_each_panel_in_a_row_and_a_column(): void
    {
        $seat = TagCategory::create(['scope' => TagScope::Project, 'name' => 'seat test']);
        $one = $seat->values()->create(['name' => 'seat 1', 'position' => 1]);
        $three = $seat->values()->create(['name' => 'seat 3', 'position' => 2]);
        $this->makeRun(['A'], [['A', 10]], ['pipeline' => 'QualityPipeline'])->setTag($seat, $three);
        $this->makeRun(['A'], [['A', 10]], ['pipeline' => 'OrchestratorPipeline'])->setTag($seat, $one);

        $result = $this->answer(['split_by' => ['pipeline', "project_tag:{$seat->id}"]]);
        $quality = collect($result->panels)->firstWhere('key', "QualityPipeline|{$three->id}");

        $this->assertSame('QualityPipeline', $quality->row);
        $this->assertSame(app(PipelineRegistry::class)->options()['QualityPipeline'], $quality->rowLabel);
        $this->assertSame((string) $three->id, $quality->column);
        $this->assertSame('Seat test: seat 3', $quality->columnLabel);
        $this->assertSame([$one->id => 'Seat test: seat 1', $three->id => 'Seat test: seat 3'], $result->columns);

        $single = $this->answer(['split_by' => 'pipeline']);
        $this->assertSame([], $single->columns);
        $this->assertSame('', $single->panels[0]->row);
    }

    public function test_two_splits_give_a_panel_per_combination_in_dimension_order(): void
    {
        $seat = TagCategory::create(['scope' => TagScope::Project, 'name' => 'seat test']);
        $one = $seat->values()->create(['name' => 'seat 1', 'position' => 1]);
        $three = $seat->values()->create(['name' => 'seat 3', 'position' => 2]);
        $this->makeRun(['A'], [['A', 10]], ['pipeline' => 'QualityPipeline'])->setTag($seat, $three);
        $this->makeRun(['A'], [['A', 10]], ['pipeline' => 'QualityPipeline'])->setTag($seat, $one);
        $this->makeRun(['A'], [['A', 10]], ['pipeline' => 'OrchestratorPipeline'])->setTag($seat, $one);
        $this->makeRun(['A'], [['A', 10]], ['pipeline' => 'OrchestratorPipeline']); // no seat: drops out

        $result = $this->answer(['split_by' => ['pipeline', "project_tag:{$seat->id}"]]);

        $pipelines = app(PipelineRegistry::class)->options();
        $byPipeline = [
            'OrchestratorPipeline' => ["OrchestratorPipeline|{$one->id}" => $pipelines['OrchestratorPipeline'].' · Seat test: seat 1'],
            'QualityPipeline' => [
                "QualityPipeline|{$one->id}" => $pipelines['QualityPipeline'].' · Seat test: seat 1',
                "QualityPipeline|{$three->id}" => $pipelines['QualityPipeline'].' · Seat test: seat 3',
            ],
        ];
        $expected = array_merge(...array_values(array_intersect_key(array_replace(array_flip(array_keys($pipelines)), $byPipeline), $byPipeline)));

        $this->assertSame($expected, array_column(array_map(fn ($panel) => ['key' => $panel->key, 'label' => $panel->label], $result->panels), 'label', 'key'));
        $this->assertSame(1, $result->excluded);
        $this->assertSame([1, 1, 1], array_map(fn ($panel) => $panel->runs, $result->panels));
    }

    public function test_split_by_pipeline_gives_panels_in_registry_order(): void
    {
        $this->makeRun(['A'], [['A', 10]], ['pipeline' => 'ScorePipeline']);
        $this->makeRun(['A'], [['A', 10]], ['pipeline' => 'RoundRobinPipeline']);

        $keys = array_map(fn ($panel) => $panel->key, $this->answer(['split_by' => 'pipeline'])->panels);
        $expected = array_values(array_intersect(array_keys(app(PipelineRegistry::class)->options()), ['ScorePipeline', 'RoundRobinPipeline']));

        $this->assertSame($expected, $keys);
    }

    public function test_several_expert_names_pick_each_of_those_agents(): void
    {
        $this->makeRun(['A', 'B', 'C'], [['A', 10], ['B', 10], ['C', 10], ['C', 10]]);

        $together = $this->answer(['people' => ['expert_names' => ['A', 'B']]])->panels[0]->groups[0];
        $perHead = $this->answer(['people' => ['expert_names' => ['A', 'B']], 'aggregate' => 'per_head'])->panels[0]->groups[0];

        $this->assertSame(0.5, $together->mean());
        $this->assertEqualsWithDelta(2 / 3, $together->fairShare(), 1e-9);
        $this->assertSame(0.25, $perHead->mean());
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

    public function test_runs_of_equal_length_are_not_capped_when_no_bound_was_asked(): void
    {
        $this->makeRun(['A'], [['A', 10], ['A', 10], ['A', 10]]);
        $this->makeRun(['A'], [['A', 10], ['A', 10], ['A', 10]]);

        $result = $this->answer([]);

        $this->assertSame(3, $result->turns->to);
        $this->assertFalse($result->cappedByShortestRun, 'nothing is lost: every run is read in full');
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

    public function test_the_result_carries_the_shortest_run_length(): void
    {
        $this->makeRun(['A'], [['A', 10], ['A', 10], ['A', 10]]);
        $this->makeRun(['A'], [['A', 10], ['A', 10]]);

        $this->assertSame(2, $this->answer([])->shortestRun);
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

    private function fileInGroup(Project $project, ?ProjectGroup $group): Project
    {
        $project->addContributingUser($this->user);
        $project->fileInGroupFor($this->user, $group?->id);

        return $project;
    }

    public function test_compare_by_group_uses_the_users_groups_in_sidebar_order_and_drops_unfiled_runs(): void
    {
        $quality = $this->user->projectGroups()->create(['name' => 'Quality', 'position' => 2]);
        $score = $this->user->projectGroups()->create(['name' => 'Score', 'position' => 1]);
        $this->user->projectGroups()->create(['name' => 'Empty', 'position' => 3]);
        $this->fileInGroup($this->makeRun(['A', 'B'], [['A', 10], ['B', 10]]), $quality);
        $this->fileInGroup($this->makeRun(['A', 'B'], [['A', 10], ['A', 10]]), $score);
        $this->fileInGroup($this->makeRun(['A', 'B'], [['A', 10], ['B', 10]]), null);

        $result = $this->answer(['people' => ['expert_name' => 'A'], 'compare_by' => 'group']);
        $groups = $result->panels[0]->groups;

        $this->assertSame([(string) $score->id, (string) $quality->id], array_map(fn ($g) => $g->key, $groups));
        $this->assertSame(['Score', 'Quality'], array_map(fn ($g) => $g->label, $groups));
        $this->assertSame([1.0, 0.5], array_map(fn ($g) => $g->mean(), $groups));
        $this->assertSame(3, $result->runs);
        $this->assertSame(1, $result->excluded);
    }

    public function test_split_by_group_gives_a_panel_per_group(): void
    {
        $score = $this->user->projectGroups()->create(['name' => 'Score', 'position' => 1]);
        $quality = $this->user->projectGroups()->create(['name' => 'Quality', 'position' => 2]);
        $this->fileInGroup($this->makeRun(['A'], [['A', 10]]), $quality);
        $this->fileInGroup($this->makeRun(['A'], [['A', 10]]), $score);

        $panels = $this->answer(['split_by' => 'group'])->panels;

        $this->assertSame(['Score', 'Quality'], array_map(fn ($p) => $p->label, $panels));
        $this->assertSame([1, 1], array_map(fn ($p) => $p->runs, $panels));
    }

    public function test_another_users_groups_do_not_count(): void
    {
        $other = User::factory()->create();
        $theirs = $other->projectGroups()->create(['name' => 'Theirs', 'position' => 0]);
        $run = $this->makeRun(['A'], [['A', 10]]);
        $run->addContributingUser($other);
        $run->fileInGroupFor($other, $theirs->id);

        $result = $this->answer(['compare_by' => 'group']);

        $this->assertSame([], $result->panels);
        $this->assertSame(1, $result->excluded);
    }

    /** @return array{0: TagCategory, 1: TagValue, 2: TagValue} the category, its "with" value and its "without" value */
    private function conditionRuns(): array
    {
        $category = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
        $with = $category->values()->create(['name' => 'mit', 'position' => 1]);
        $without = $category->values()->create(['name' => 'ohne', 'position' => 2]);
        $this->makeRun(['A', 'B'], [['A', 10], ['B', 10], ['A', 10], ['B', 10]])->setTag($category, $without);
        $this->makeRun(['A', 'B'], [['A', 10], ['A', 10], ['A', 10], ['B', 10]])->setTag($category, $with);

        return [$category, $with, $without];
    }

    public function test_the_baseline_comes_first_and_the_delta_is_the_other_group_minus_it(): void
    {
        [$category, $with, $without] = $this->conditionRuns();

        $result = $this->answer(['people' => ['expert_name' => 'A'], 'compare_by' => "project_tag:{$category->id}", 'baseline' => (string) $without->id]);
        $panel = $result->panels[0];

        $this->assertSame((string) $without->id, $result->baseline);
        $this->assertSame([(string) $without->id, (string) $with->id], array_map('strval', array_keys($result->groups)));
        $this->assertSame(['Bedingung: ohne', 'Bedingung: mit'], array_map(fn ($g) => $g->label, $panel->groups));
        $this->assertSame(0.25, $panel->delta());
    }

    public function test_reordering_the_tag_values_does_not_flip_the_delta(): void
    {
        [$category, $with, $without] = $this->conditionRuns();
        $question = ['people' => ['expert_name' => 'A'], 'compare_by' => "project_tag:{$category->id}", 'baseline' => (string) $without->id];
        $before = $this->answer($question)->panels[0]->delta();

        $with->update(['position' => 2]);
        $without->update(['position' => 1]);
        $after = $this->answer($question)->panels[0]->delta();

        $with->update(['position' => 1]);
        $without->update(['position' => 2]);
        $this->assertSame($before, $this->answer($question)->panels[0]->delta());
        $this->assertSame($before, $after);
        $this->assertSame(0.25, $after);
    }

    public function test_without_a_valid_baseline_the_first_group_in_dimension_order_is_it(): void
    {
        [$category, $with] = $this->conditionRuns();

        foreach ([null, '99999'] as $baseline) {
            $result = $this->answer(['people' => ['expert_name' => 'A'], 'compare_by' => "project_tag:{$category->id}", 'baseline' => $baseline]);

            $this->assertSame((string) $with->id, $result->baseline);
            $this->assertSame(-0.25, $result->panels[0]->delta());
        }

        $this->assertNull($this->answer([])->baseline);
    }

    public function test_a_rolling_curve_from_a_later_turn_looks_back_over_its_window(): void
    {
        config(['discussion.share_window' => 2]);
        $this->makeRun(['A', 'B'], [['A', 10], ['A', 10], ['B', 10], ['B', 10]]);

        $curve = $this->answer(['people' => ['expert_name' => 'A'], 'form' => 'trajectory', 'curve' => 'rolling', 'turns' => ['from' => 3]])->panels[0]->groups[0]->curve;

        $this->assertSame([3, 4], array_map(fn ($mean) => $mean->turn, $curve));
        // Turn 3 rolls over turns 2 and 3: A once, B once. Turn 4 over 3 and 4: B twice.
        $this->assertEqualsWithDelta([0.5, 0.0], array_map(fn ($mean) => $mean->mean, $curve), 1e-9);
    }

    public function test_a_cumulative_curve_from_a_later_turn_starts_counting_there(): void
    {
        $this->makeRun(['A', 'B'], [['A', 10], ['A', 10], ['B', 10], ['A', 10]]);

        $curve = $this->answer(['people' => ['expert_name' => 'A'], 'form' => 'trajectory', 'turns' => ['from' => 3]])->panels[0]->groups[0]->curve;

        $this->assertEqualsWithDelta([0.0, 0.5], array_map(fn ($mean) => $mean->mean, $curve), 1e-9);
    }

    public function test_the_queries_of_an_answer_grow_by_a_few_per_run_only(): void
    {
        $persona = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'rolle']);
        $titled = $persona->values()->create(['name' => 'titel']);
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
        $with = $condition->values()->create(['name' => 'mit']);

        foreach (range(1, 6) as $run) {
            $this->makeRun(["A{$run}", "B{$run}"], [["A{$run}", 10], ["B{$run}", 10]])->setTag($condition, $with);
            $this->experts["A{$run}"]->setTag($persona, $titled);
        }

        DB::enableQueryLog();
        $this->answer(['people' => ['tags' => [$persona->id => [$titled->id]]], 'compare_by' => "persona_tag:{$persona->id}", 'split_by' => "project_tag:{$condition->id}", 'form' => 'trajectory']);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Per run: participants, the whole-run and the window report (spoken turns and failed turns each), the curve,
        // and who left the run (once for the totals, once for the curve).
        $this->assertLessThanOrEqual(14 + 6 * 8, $queries, "{$queries} queries for 6 runs");
    }

    public function test_the_index_of_a_group_the_answer_does_not_have_is_an_error(): void
    {
        $this->makeRun(['A'], [['A', 10]]);

        $this->expectException(\LogicException::class);
        $this->answer([])->groupIndex('missing');
    }

    public function test_asking_the_same_question_twice_reads_the_data_once_per_instance(): void
    {
        $this->makeRun(['Ann', 'Ben'], [['Ann', 10], ['Ben', 20]]);
        $evaluator = app(QuestionEvaluator::class);
        $question = QuestionDefinition::fromArray([]);

        DB::enableQueryLog();
        $first = $evaluator->evaluate($this->user, $question);
        $queries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $second = $evaluator->evaluate($this->user, $question);

        $this->assertGreaterThan(0, $queries);
        $this->assertCount(0, DB::getQueryLog());
        $this->assertSame($first, $second);

        // The next request, a new instance, reads afresh.
        $this->assertNotSame($first, app(QuestionEvaluator::class)->evaluate($this->user, $question));
    }

    public function test_the_tables_the_statistics_read_by_project_have_an_index_on_it(): void
    {
        foreach (['job_logs' => 'project_id', 'messages' => 'project_id', 'project_contributors' => 'project_id'] as $table => $column) {
            $leading = collect(Schema::getIndexes($table))->contains(fn (array $index) => ($index['columns'][0] ?? null) === $column);

            $this->assertTrue($leading, "{$table} has no index starting with {$column}");
        }
    }
}
