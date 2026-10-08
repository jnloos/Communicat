<?php

namespace Tests\Feature\Statistics;

use App\Discussion\Pipelines\PipelineRegistry;
use App\Livewire\Statistics\ComparisonEditor;
use App\Livewire\Statistics\StatisticsPage;
use App\Models\Comparison;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ComparisonEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Comparison $comparison;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->comparison = $this->user->comparisons()->create(['name' => 'Status', 'series' => [], 'view' => []]);
    }

    private function editor(): mixed
    {
        return Livewire::test(ComparisonEditor::class, ['comparisonId' => $this->comparison->id]);
    }

    public function test_the_page_shows_the_editor_of_the_open_comparison(): void
    {
        Livewire::test(StatisticsPage::class, ['comparisonId' => $this->comparison->id])
            ->assertSeeLivewire(ComparisonEditor::class);
    }

    public function test_someone_elses_comparison_cannot_be_edited(): void
    {
        $foreign = User::factory()->create()->comparisons()->create(['name' => 'X', 'series' => [], 'view' => []]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::test(ComparisonEditor::class, ['comparisonId' => $foreign->id]);
    }

    public function test_every_change_is_normalised_and_saved_at_once(): void
    {
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $status = $condition->values()->create(['name' => 'status']);

        $this->editor()
            ->call('addSeries')
            ->set("series.0.projects.tags.{$condition->id}", [(string) $status->id])
            ->set('series.0.reference.type', 'seat')
            ->set('viewSettings.metric', 'word_share');

        $saved = $this->comparison->fresh();
        $this->assertSame([$condition->id => [$status->id]], $saved->series[0]['projects']['tags']);
        $this->assertSame(['type' => 'seat', 'seat' => 1], $saved->series[0]['reference']);
        $this->assertSame('word_share', $saved->view['metric']);
        $this->assertSame(__('comparisons.series.default_label', ['n' => 1]), $saved->series[0]['label']);
    }

    public function test_removing_a_series_saves_too(): void
    {
        $this->editor()->call('addSeries')->call('addSeries')->call('removeSeries', 0);

        $this->assertCount(1, $this->comparison->fresh()->series);
        $this->assertSame(__('comparisons.series.default_label', ['n' => 2]), $this->comparison->fresh()->series[0]['label']);
    }

    public function test_the_live_match_list_shows_only_readable_projects(): void
    {
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $status = $condition->values()->create(['name' => 'status']);
        $mine = Project::factory()->create(['user_id' => $this->user->id, 'title' => 'Meine Zelle']);
        $foreign = Project::factory()->create(['title' => 'Fremde Zelle']);
        $mine->setTag($condition, $status);
        $foreign->setTag($condition, $status);

        $component = $this->editor()
            ->call('addSeries')
            ->set("series.0.projects.tags.{$condition->id}", [(string) $status->id]);

        $this->assertSame([$mine->id], $component->viewData('matches')[0]->pluck('id')->all());
        $component->assertSee('Meine Zelle')->assertDontSee('Fremde Zelle');
    }

    public function test_deleted_tag_values_drop_out_on_load_with_a_notice(): void
    {
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $status = $condition->values()->create(['name' => 'status']);
        $control = $condition->values()->create(['name' => 'no-status']);
        $this->comparison->update(['series' => [[
            'label' => 'Reihe',
            'projects' => ['tags' => [$condition->id => [$status->id, $control->id]]],
            'reference' => ['type' => 'each'],
        ]]]);
        $control->delete();

        $this->editor()
            ->assertSet('droppedTagValues', 1)
            ->assertSet('series.0.projects.tags', [$condition->id => [$status->id]])
            ->assertSee(trans_choice('comparisons.dropped_tags', 1, ['count' => 1]));
    }

    public function test_a_project_filter_whose_values_all_vanished_matches_nothing_and_names_the_series(): void
    {
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $status = $condition->values()->create(['name' => 'status']);
        Project::factory()->create(['user_id' => $this->user->id]);
        $this->comparison->update(['series' => [[
            'label' => 'Titelträger',
            'projects' => ['tags' => [$condition->id => [$status->id]]],
            'reference' => ['type' => 'each'],
        ]]]);
        $status->delete();

        $component = $this->editor();

        $this->assertCount(0, $component->viewData('matches')[0]);
        $component->assertSee(trans_choice('comparisons.emptied_series', 1, ['labels' => __('comparisons.quoted', ['label' => 'Titelträger'])]));
    }

    public function test_a_value_deleted_after_loading_does_not_widen_the_series(): void
    {
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $status = $condition->values()->create(['name' => 'status']);
        Project::factory()->create(['user_id' => $this->user->id]);

        $component = $this->editor()
            ->call('addSeries')
            ->set("series.0.projects.tags.{$condition->id}", [(string) $status->id]);
        $status->delete();
        $component->call('$refresh');

        $this->assertCount(0, $component->viewData('matches')[0]);
    }

    public function test_a_partly_deleted_filter_keeps_filtering_by_the_remaining_value(): void
    {
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $status = $condition->values()->create(['name' => 'status']);
        $control = $condition->values()->create(['name' => 'no-status']);
        $tagged = Project::factory()->create(['user_id' => $this->user->id]);
        $tagged->setTag($condition, $status);
        Project::factory()->create(['user_id' => $this->user->id]);

        $component = $this->editor()
            ->call('addSeries')
            ->set("series.0.projects.tags.{$condition->id}", [(string) $status->id, (string) $control->id]);
        $control->delete();
        $component->call('$refresh');

        $this->assertSame([$tagged->id], $component->viewData('matches')[0]->pluck('id')->all());
    }

    public function test_removing_a_series_asks_first(): void
    {
        $component = $this->editor()
            ->call('addSeries')
            ->call('confirmRemoveSeries', 0)
            ->assertDispatched('open-confirm', title: __('comparisons.confirm.remove_series.title'),
                message: __('comparisons.confirm.remove_series.message', ['label' => __('comparisons.series.default_label', ['n' => 1])]));

        $this->assertCount(1, $this->comparison->fresh()->series);

        $component->call('executeConfirmed');
        $this->assertCount(0, $this->comparison->fresh()->series);
    }

    public function test_a_new_series_takes_the_next_free_number(): void
    {
        $this->editor()->call('addSeries')->call('addSeries')->call('removeSeries', 0)->call('addSeries');

        $this->assertSame(
            [__('comparisons.series.default_label', ['n' => 2]), __('comparisons.series.default_label', ['n' => 3])],
            array_column($this->comparison->fresh()->series, 'label'),
        );
    }

    public function test_the_editor_says_it_saves_by_itself(): void
    {
        $this->editor()->assertSee(__('comparisons.autosave.saved'))->assertSee(__('comparisons.autosave.saving'));
    }

    public function test_the_name_heads_the_editor_and_follows_a_rename(): void
    {
        $component = $this->editor()->assertSee('Status')->assertSee(__('comparisons.actions.rename'));

        $this->comparison->update(['name' => 'Titelträger']);

        $component->dispatch('comparison-renamed')->assertSee('Titelträger');
    }

    public function test_the_match_list_is_folded_with_its_count_visible(): void
    {
        Project::factory()->create(['user_id' => $this->user->id, 'title' => 'Zelle A']);

        $html = $this->editor()->call('addSeries')->assertSee(trans_choice('comparisons.series.matches', 1, ['count' => 1]))->html();

        $this->assertMatchesRegularExpression('/<details class="group text-sm">\s*<summary/', $html);
    }

    public function test_each_series_card_carries_a_one_line_summary(): void
    {
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'condition']);
        $status = $condition->values()->create(['name' => 'status']);
        Project::factory()->count(2)->create(['user_id' => $this->user->id, 'pipeline' => 'ScorePipeline'])
            ->each(fn (Project $project) => $project->setTag($condition, $status));
        $this->comparison->update(['series' => [[
            'label' => 'Score · status',
            'projects' => ['tags' => [$condition->id => [$status->id]], 'pipeline' => 'ScorePipeline'],
            'reference' => ['type' => 'seat', 'seat' => 1],
        ]]]);

        $summary = implode(' · ', [
            'Condition: status',
            app(PipelineRegistry::class)->options()['ScorePipeline'],
            __('comparisons.series.any_model'),
            __('comparisons.results.seat', ['seat' => 1]),
            trans_choice('comparisons.series.summary_projects', 2, ['count' => 2]),
        ]);

        $this->editor()->assertViewHas('summaries', [$summary])->assertSee($summary);
    }

    public function test_cards_start_open_up_to_three_series_and_folded_beyond(): void
    {
        $this->comparison->update(['series' => array_fill(0, 3, ['label' => 'A'])]);
        $this->assertSame([true, true, true], $this->editor()->viewData('openCards'));

        $this->comparison->update(['series' => array_fill(0, 4, ['label' => 'A'])]);
        $editor = $this->editor();
        $this->assertSame([false, false, false, false], $editor->viewData('openCards'));

        // A series just added opens, so it can be filled in; folding saves nothing.
        $this->assertSame([false, false, false, false, true], $editor->call('addSeries')->viewData('openCards'));
        $this->assertCount(5, $this->comparison->fresh()->series);
    }
}
