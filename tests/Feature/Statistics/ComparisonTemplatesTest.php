<?php

namespace Tests\Feature\Statistics;

use App\Discussion\Metrics\SeriesProjects;
use App\Discussion\Metrics\Templates\ComparisonTemplates;
use App\Discussion\Values\SeriesDefinition;
use App\Livewire\Statistics\ComparisonEditor;
use App\Livewire\Statistics\StatisticsPage;
use App\Models\Comparison;
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
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);

        return [$condition, $condition->values()->create(['name' => 'status'])->id, $condition->values()->create(['name' => 'control'])->id];
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

        $this->assertCount(7, app(ComparisonTemplates::class)->all());
    }

    public function test_every_template_creates_a_comparison_with_resolved_tag_ids(): void
    {
        [$condition, $status, $control] = $this->conditions();

        foreach (app(ComparisonTemplates::class)->all() as $key => $template) {
            $comparison = $this->createFrom($key);

            $this->assertSame($template->name(), $comparison->name);
            $this->assertSame($this->user->id, $comparison->user_id);
            $this->assertSame(60, $comparison->view['turn_limit'], $key);
            $this->assertNotEmpty($comparison->series, $key);

            foreach ($comparison->seriesDefinitions() as $series) {
                $this->assertContains($series->projectTags->toArray(), [[$condition->id => [$status]], [$condition->id => [$control]]], $key);
            }
        }
    }

    public function test_the_pipeline_templates_pair_status_and_control_per_pipeline_on_seat_one(): void
    {
        [$condition, $status, $control] = $this->conditions();

        $series = $this->createFrom('turn_share_by_pipeline')->seriesDefinitions();

        $this->assertCount(10, $series);
        $this->assertSame('Score · status', $series[0]->label);
        $this->assertLessThanOrEqual(count(ComparisonEditor::PALETTE), count($series), 'ten series, ten colours');
        $this->assertSame('ScorePipeline', $series[0]->pipeline);
        $this->assertSame([$condition->id => [$status]], $series[0]->projectTags->toArray());
        $this->assertSame([$condition->id => [$control]], $series[1]->projectTags->toArray());
        $this->assertSame(['type' => 'seat', 'seat' => 1], $series[0]->reference->toArray());
        $this->assertSame(
            ['ScorePipeline', 'OrchestratorPipeline', 'QualityPipeline', 'RoundRobinPipeline', 'BlindQualityPipeline'],
            array_values(array_unique(array_map(fn (SeriesDefinition $s) => $s->pipeline, $series))),
        );
    }

    public function test_the_views_of_the_templates(): void
    {
        $this->conditions();
        $views = [
            'turn_share_by_pipeline' => ['turn_share', 'bars'],
            'word_share_by_pipeline' => ['word_share', 'bars'],
            'inequality_by_pipeline' => ['turn_gini', 'bars'],
            'words_per_turn_seat_one' => ['words_per_turn', 'bars'],
            'seat_one_over_the_run' => ['turn_share', 'trajectory'],
            'blind_vs_seeing_judge' => ['turn_share', 'bars'],
            'every_seat_by_condition' => ['turn_share', 'pie'],
        ];

        foreach ($views as $key => [$metric, $display]) {
            $view = $this->createFrom($key)->view;
            $this->assertSame([$metric, $display, 'cumulative'], [$view['metric'], $view['display'], $view['trajectory_mode']], $key);
        }

        $this->assertSame(['each'], array_unique(array_column(array_column($this->createFrom('every_seat_by_condition')->series, 'reference'), 'type')));
        $this->assertSame(
            ['QualityPipeline', 'QualityPipeline', 'BlindQualityPipeline', 'BlindQualityPipeline'],
            array_map(fn (SeriesDefinition $s) => $s->pipeline, $this->createFrom('blind_vs_seeing_judge')->seriesDefinitions()),
        );
    }

    public function test_unknown_tag_names_never_widen_the_filter(): void
    {
        // A project the template would match on its pipeline alone.
        Project::factory()->create(['user_id' => $this->user->id, 'pipeline' => 'ScorePipeline']);

        $comparison = $this->createFrom('turn_share_by_pipeline');
        $series = $comparison->seriesDefinitions()[0];

        $this->assertSame([0 => [0]], $series->projectTags->toArray());
        $this->assertCount(0, app(SeriesProjects::class)->for($this->user, $series));

        // The editor's existing notice explains it instead of showing an unfiltered result.
        Livewire::test(ComparisonEditor::class, ['comparisonId' => $comparison->id])
            ->assertSee(__('comparisons.quoted', ['label' => 'Score · status']), false);
    }

    public function test_a_known_category_with_unknown_values_matches_nothing(): void
    {
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $other = $condition->values()->create(['name' => 'something else']);
        Project::factory()->create(['user_id' => $this->user->id, 'pipeline' => 'ScorePipeline'])->setTag($condition, $other);

        $series = $this->createFrom('turn_share_by_pipeline')->seriesDefinitions()[0];

        $this->assertSame([$condition->id => [0]], $series->projectTags->toArray());
        $this->assertCount(0, app(SeriesProjects::class)->for($this->user, $series));
    }

    public function test_an_unknown_template_does_not_exist(): void
    {
        Livewire::test(StatisticsPage::class)->call('createFromTemplate', 'nope')->assertStatus(404);

        $this->assertSame(0, Comparison::count());
    }
}
