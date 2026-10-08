<?php

namespace Tests\Feature\Statistics;

use App\Discussion\Metrics\SeriesProjects;
use App\Discussion\Metrics\SeriesStatistics;
use App\Discussion\Metrics\SpeakingShares;
use App\Discussion\Metrics\Templates\ComparisonTemplates;
use App\Discussion\Values\AgentGroup;
use App\Discussion\Values\ComparisonView;
use App\Discussion\Values\Metric;
use App\Discussion\Values\SeriesDefinition;
use App\Discussion\Values\SeriesResult;
use App\Livewire\Statistics\ComparisonEditor;
use App\Livewire\Statistics\StatisticsPage;
use App\Models\Comparison;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ComparisonTemplatesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    /** @return array{0: TagCategory, 1: int, 2: int} the condition category, status id, control id */
    private function conditions(): array
    {
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);

        return [$condition, $condition->values()->create(['name' => 'status'])->id, $condition->values()->create(['name' => 'no-status'])->id];
    }

    /** @return array{0: TagCategory, 1: int, 2: int} the title-holder category, its true id, its false id */
    private function titles(): array
    {
        $title = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'title-holder']);

        return [$title, $title->values()->create(['name' => 'true'])->id, $title->values()->create(['name' => 'false'])->id];
    }

    private function createFrom(string $key): Comparison
    {
        Livewire::test(StatisticsPage::class)->call('createFromTemplate', $key)->assertSet('comparisonId', Comparison::latest('id')->first()->id);

        return Comparison::latest('id')->first();
    }

    public function test_the_page_lists_every_template(): void
    {
        $component = Livewire::test(StatisticsPage::class)->assertSee(__('comparisons.templates.menu'));

        foreach (app(ComparisonTemplates::class)->all() as $template) {
            $component->assertSee($template->name());
        }

        $this->assertSame(
            ['turn_share_by_pipeline', 'word_share_by_pipeline', 'inequality_by_pipeline', 'turn_share_overall', 'word_share_overall', 'blind_vs_seeing_judge'],
            array_keys(app(ComparisonTemplates::class)->all()),
        );
    }

    public function test_every_template_creates_a_comparison_with_resolved_tag_ids(): void
    {
        [$condition, $status, $control] = $this->conditions();
        $this->titles();

        foreach (app(ComparisonTemplates::class)->all() as $key => $template) {
            $comparison = $this->createFrom($key);

            $this->assertSame($template->name(), $comparison->name);
            $this->assertSame($this->user->id, $comparison->user_id);
            $this->assertSame(60, $comparison->view['turn_limit'], $key);
            $this->assertNotEmpty($comparison->series, $key);

            foreach ($comparison->seriesDefinitions() as $series) {
                $this->assertContains($series->projectTags->toArray(), [[$condition->id => [$status]], [$condition->id => [$control]]], $key);
                $this->assertSame([], $series->unresolvedTags, $key);
            }
        }
    }

    public function test_the_pipeline_templates_pair_title_holder_and_others_within_status_projects(): void
    {
        [$condition, $status] = $this->conditions();
        [$title, $holder, $others] = $this->titles();

        foreach (['turn_share_by_pipeline', 'word_share_by_pipeline'] as $key) {
            $series = $this->createFrom($key)->seriesDefinitions();

            $this->assertCount(10, $series, $key);
            $this->assertSame(['Score · title holder', 'Score · others'], [$series[0]->label, $series[1]->label], $key);
            // One hue per pipeline: the title holder in the full shade, the others in the light one.
            $colours = array_map(fn (SeriesDefinition $s) => (string) $s->color, $series);
            $this->assertSame(['indigo-500', 'indigo-300'], array_slice($colours, 0, 2), $key);
            $this->assertCount(10, array_unique($colours), 'no two series share a colour');
            $this->assertCount(5, array_unique(array_map(fn (SeriesDefinition $s) => $s->color->hue, $series)), $key);
            $this->assertSame('ScorePipeline', $series[0]->pipeline, $key);
            // Both series of a pair look at the status projects only, and resolve the persona tag.
            $this->assertSame([[$condition->id => [$status]]], array_values(array_unique(array_map(fn (SeriesDefinition $s) => $s->projectTags->toArray(), $series), SORT_REGULAR)), $key);
            $this->assertSame(['type' => 'persona_tags', 'tags' => [$title->id => [$holder]]], $series[0]->reference->toArray(), $key);
            $this->assertSame(['type' => 'persona_tags', 'tags' => [$title->id => [$others]]], $series[1]->reference->toArray(), $key);
            $this->assertSame(
                ['ScorePipeline', 'OrchestratorPipeline', 'QualityPipeline', 'RoundRobinPipeline', 'BlindQualityPipeline'],
                array_values(array_unique(array_map(fn (SeriesDefinition $s) => $s->pipeline, $series))),
            );
        }
    }

    public function test_the_inequality_template_pairs_status_and_no_status_per_pipeline(): void
    {
        [$condition, $status, $control] = $this->conditions();

        $series = $this->createFrom('inequality_by_pipeline')->seriesDefinitions();

        $this->assertCount(10, $series);
        $this->assertSame(['Score · status', 'Score · no-status'], [$series[0]->label, $series[1]->label]);
        $this->assertSame([$condition->id => [$status]], $series[0]->projectTags->toArray());
        $this->assertSame([$condition->id => [$control]], $series[1]->projectTags->toArray());
        $this->assertSame(['indigo-500', 'indigo-300'], [(string) $series[0]->color, (string) $series[1]->color]);
    }

    public function test_a_title_holder_series_takes_the_titled_persona_and_the_others_series_the_rest(): void
    {
        [$condition, $status] = $this->conditions();
        [$title] = $this->titles();
        $project = Project::factory()->create(['user_id' => $this->user->id, 'pipeline' => 'ScorePipeline']);
        $project->setTag($condition, $condition->values()->find($status));
        $experts = Expert::factory()->count(4)->create();
        $experts->each(fn (Expert $expert, int $i) => $expert->setTag($title, $title->values()->where('name', $i === 0 ? 'true' : 'false')->sole()));
        $experts->each(fn (Expert $expert) => $project->addContributingExpert($expert));
        // The titled persona speaks four of eight turns, the three others the rest.
        foreach ([0, 0, 1, 0, 2, 0, 3, 1] as $index => $speaker) {
            JobLog::create([
                'job_class' => 'App\Discussion\TurnRunner', 'project_id' => $project->id, 'status' => 'success',
                'started_at' => now(), 'turn_index' => $index + 1, 'expert_id' => $experts[$speaker]->id, 'words' => 10,
            ]);
        }

        $series = $this->createFrom('turn_share_by_pipeline')->seriesDefinitions();
        [$holder, $others] = [$this->statistics($series[0])->lines, $this->statistics($series[1])->lines];

        $this->assertCount(1, $holder);
        $this->assertSame(0.5, $holder[0]->mean());
        $this->assertCount(1, $others);
        $this->assertSame(0.5, $others[0]->mean(), 'the three others count with their summed share');
        $report = app(SpeakingShares::class)->forProject($project);
        $picked = fn (SeriesDefinition $s) => array_map(fn (AgentGroup $group) => $group->expertIds, $s->reference->pick($project, $report, Metric::TurnShare));
        $this->assertSame([[$experts[0]->id]], $picked($series[0]));
        $this->assertSame([$experts->slice(1)->pluck('id')->values()->all()], $picked($series[1]));
    }

    public function test_an_unknown_persona_tag_is_shown_and_never_widens_the_reference(): void
    {
        [$condition, $status] = $this->conditions();
        $title = TagCategory::create(['scope' => TagScope::Persona, 'name' => 'Title-Holder']);
        $holder = $title->values()->create(['name' => 'TRUE'])->id;
        Project::factory()->create(['user_id' => $this->user->id, 'pipeline' => 'ScorePipeline'])->setTag($condition, $condition->values()->find($status));

        $series = $this->createFrom('turn_share_by_pipeline')->seriesDefinitions();

        // Case-insensitive like the project tags; `false` does not exist here.
        $this->assertSame(['type' => 'persona_tags', 'tags' => [$title->id => [$holder]]], $series[0]->reference->toArray());
        $this->assertSame([], $series[0]->unresolvedTags);
        $this->assertSame(['type' => 'persona_tags', 'tags' => []], $series[1]->reference->toArray());
        $this->assertSame([['category' => 'Title-Holder', 'value' => 'false']], $series[1]->unresolvedTags);
        $this->assertCount(0, app(SeriesProjects::class)->for($this->user, $series[1]));
    }

    private function statistics(SeriesDefinition $series): SeriesResult
    {
        return app(SeriesStatistics::class)->compute($series, ComparisonView::fromArray(['metric' => 'turn_share']), app(SeriesProjects::class)->for($this->user, $series));
    }

    public function test_the_views_of_the_templates(): void
    {
        $this->conditions();
        $views = [
            'turn_share_by_pipeline' => ['turn_share', 'bars'],
            'word_share_by_pipeline' => ['word_share', 'bars'],
            'inequality_by_pipeline' => ['turn_gini', 'bars'],
            'turn_share_overall' => ['turn_share', 'bars'],
            'word_share_overall' => ['word_share', 'bars'],
            'blind_vs_seeing_judge' => ['turn_share', 'bars'],
        ];

        foreach ($views as $key => [$metric, $display]) {
            $view = $this->createFrom($key)->view;
            $this->assertSame([$metric, $display, 'cumulative'], [$view['metric'], $view['display'], $view['trajectory_mode']], $key);
        }

        $judges = $this->createFrom('blind_vs_seeing_judge')->seriesDefinitions();
        $this->assertSame(
            ['QualityPipeline', 'QualityPipeline', 'BlindQualityPipeline', 'BlindQualityPipeline'],
            array_map(fn (SeriesDefinition $s) => $s->pipeline, $judges),
        );
        $this->assertSame(
            ['Quality · title holder', 'Quality · others', 'Quality blind · title holder', 'Quality blind · others'],
            array_map(fn (SeriesDefinition $s) => $s->label, $judges),
        );
    }

    public function test_the_overall_templates_compare_title_holder_and_others_across_all_pipelines(): void
    {
        [$condition, $status] = $this->conditions();
        [$title, $holder, $others] = $this->titles();

        foreach (['turn_share_overall', 'word_share_overall'] as $key) {
            $series = $this->createFrom($key)->seriesDefinitions();

            $this->assertCount(2, $series, $key);
            $this->assertSame(['title holder', 'others'], array_map(fn (SeriesDefinition $s) => $s->label, $series), $key);
            $this->assertSame([null, null], array_map(fn (SeriesDefinition $s) => $s->pipeline, $series), $key);
            $this->assertSame([[$condition->id => [$status]], [$condition->id => [$status]]], array_map(fn (SeriesDefinition $s) => $s->projectTags->toArray(), $series), $key);
            $this->assertSame([[$title->id => [$holder]], [$title->id => [$others]]], array_map(fn (SeriesDefinition $s) => $s->reference->toArray()['tags'], $series), $key);
            $this->assertSame(['indigo-500', 'indigo-300'], array_map(fn (SeriesDefinition $s) => (string) $s->color, $series), $key);
        }
    }

    public function test_unknown_tag_names_never_widen_the_filter(): void
    {
        // A project the template would match on its pipeline alone.
        Project::factory()->create(['user_id' => $this->user->id, 'pipeline' => 'ScorePipeline']);

        $comparison = $this->createFrom('turn_share_by_pipeline');
        $series = $comparison->seriesDefinitions()[0];

        $this->assertSame([], $series->projectTags->toArray(), 'no placeholder id 0');
        $this->assertSame([['category' => 'status condition', 'value' => 'status'], ['category' => 'title-holder', 'value' => 'true']], $series->unresolvedTags);
        $this->assertCount(0, app(SeriesProjects::class)->for($this->user, $series));

        // The editor names the series and the unknown tag, and reports no deletion.
        Livewire::test(ComparisonEditor::class, ['comparisonId' => $comparison->id])
            ->assertSet('droppedTagValues', 0)
            ->assertSee('name tags that do not exist here')
            ->assertSee(__('comparisons.quoted', ['label' => 'Score · title holder']), false)
            ->assertSee('Status condition: status')
            ->assertDontSee(trans_choice('comparisons.dropped_tags', 1));
    }

    public function test_a_known_category_with_unknown_values_matches_nothing(): void
    {
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'status condition']);
        $other = $condition->values()->create(['name' => 'something else']);
        Project::factory()->create(['user_id' => $this->user->id, 'pipeline' => 'ScorePipeline'])->setTag($condition, $other);

        $series = $this->createFrom('turn_share_by_pipeline')->seriesDefinitions()[0];

        $this->assertSame([], $series->projectTags->toArray());
        $this->assertSame(['category' => 'status condition', 'value' => 'status'], $series->unresolvedTags[0]);
        $this->assertCount(0, app(SeriesProjects::class)->for($this->user, $series));
    }

    public function test_tag_names_match_regardless_of_case(): void
    {
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'Status condition']);
        $status = $condition->values()->create(['name' => 'Status'])->id;
        $control = $condition->values()->create(['name' => 'NO-STATUS'])->id;

        $series = $this->createFrom('inequality_by_pipeline')->seriesDefinitions();

        $this->assertSame([$condition->id => [$status]], $series[0]->projectTags->toArray());
        $this->assertSame([$condition->id => [$control]], $series[1]->projectTags->toArray());
        $this->assertSame([], $series[0]->unresolvedTags);
    }

    public function test_creating_from_a_template_says_once_which_tags_do_not_exist(): void
    {
        $page = Livewire::test(StatisticsPage::class)
            ->call('createFromTemplate', 'turn_share_overall')
            ->assertSee(__('comparisons.templates.unresolved', ['tags' => 'Status condition: status, Title-holder: true, Title-holder: false']));

        // One-time: opening a comparison (or dismissing it) clears it.
        $page->call('open', Comparison::latest('id')->first()->id)
            ->assertDontSee(__('comparisons.templates.unresolved', ['tags' => 'Status condition: status, Title-holder: true, Title-holder: false']));
    }

    public function test_no_notice_when_every_template_tag_exists(): void
    {
        $this->conditions();
        $this->titles();

        Livewire::test(StatisticsPage::class)
            ->call('createFromTemplate', 'turn_share_overall')
            ->assertSet('templateNotice', null);
    }

    public function test_an_unknown_tag_can_be_removed_from_its_series(): void
    {
        Project::factory()->create(['user_id' => $this->user->id, 'pipeline' => 'ScorePipeline']);
        $comparison = $this->createFrom('turn_share_by_pipeline');

        Livewire::test(ComparisonEditor::class, ['comparisonId' => $comparison->id])
            ->call('removeUnresolvedTag', 0, 0)
            ->call('removeUnresolvedTag', 0, 0)
            ->assertViewHas('unresolvedSeries', fn (array $labels) => ! in_array('Score · title holder', $labels, true) && in_array('Score · others', $labels, true));

        $series = $comparison->fresh()->seriesDefinitions();

        $this->assertSame([], $series[0]->unresolvedTags);
        $this->assertCount(1, app(SeriesProjects::class)->for($this->user, $series[0]), 'removing it is the user\'s choice to widen');
        $this->assertNotSame([], $series[1]->unresolvedTags, 'the other series keep theirs');
    }

    public function test_an_unknown_template_does_not_exist(): void
    {
        Livewire::test(StatisticsPage::class)->call('createFromTemplate', 'nope')->assertStatus(404);

        $this->assertSame(0, Comparison::count());
    }
}
