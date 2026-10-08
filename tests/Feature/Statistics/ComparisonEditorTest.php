<?php

namespace Tests\Feature\Statistics;

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
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
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
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
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
        $condition = TagCategory::create(['scope' => TagScope::Project, 'name' => 'bedingung']);
        $status = $condition->values()->create(['name' => 'status']);
        $control = $condition->values()->create(['name' => 'kontrolle']);
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
}
