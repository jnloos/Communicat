<?php

namespace Tests\Feature\Statistics;

use App\Livewire\Statistics\ComparisonEditor;
use App\Models\Comparison;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ComparisonResultsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Comparison $comparison;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        // One run of four turns: seat 1 speaks three of them.
        $project = Project::factory()->create(['user_id' => $this->user->id, 'pipeline' => 'ScorePipeline', 'title' => 'Zelle 1']);
        $first = Expert::factory()->create();
        $second = Expert::factory()->create();
        $project->addContributingExpert($first);
        $project->addContributingExpert($second);

        foreach ([$first, $first, $second, $first] as $index => $speaker) {
            JobLog::create([
                'job_class' => 'App\Discussion\TurnRunner', 'project_id' => $project->id, 'status' => 'success',
                'started_at' => now(), 'turn_index' => $index + 1, 'expert_id' => $speaker->id, 'words' => 10,
            ]);
        }

        $this->comparison = $this->user->comparisons()->create([
            'name' => 'Sitz 1',
            'series' => [['label' => 'Sitz eins', 'projects' => ['pipeline' => 'ScorePipeline'], 'reference' => ['type' => 'seat', 'seat' => 1]]],
            'view' => ['metric' => 'turn_share', 'display' => 'table'],
        ]);
    }

    private function editor(): mixed
    {
        return Livewire::test(ComparisonEditor::class, ['comparisonId' => $this->comparison->id]);
    }

    public function test_the_table_shows_mean_min_max_and_n(): void
    {
        $this->editor()
            ->assertSee('Sitz eins')
            ->assertSee('75,0 %')
            ->assertSee('Zelle 1');
    }

    public function test_the_bars_carry_mean_and_span(): void
    {
        $component = $this->editor()->set('viewSettings.display', 'bars');

        $bar = $component->viewData('bars')[0];
        $this->assertSame(0.75, $bar['mean']);
        $this->assertSame(0.75, $bar['min']);
        $this->assertSame(0.75, $bar['max']);
        $this->assertSame(1.0, $component->viewData('barScale'));
        $this->assertSame('indigo', $bar['color']);
        $component->assertSee(__('comparisons.results.bar_text', ['mean' => '75,0 %', 'min' => '75,0 %', 'max' => '75,0 %', 'n' => 1]));
    }

    public function test_the_curve_has_one_row_per_turn_with_n(): void
    {
        $rows = $this->editor()->set('viewSettings.display', 'trajectory')->viewData('trajectoryRows');

        $this->assertCount(4, $rows);
        $this->assertSame(1, $rows[0]['turn']);
        $this->assertSame(100.0, $rows[0]['l0']);
        $this->assertSame(75.0, $rows[3]['l0']);
        $this->assertSame(1, $rows[3]['n0']);
    }

    public function test_gini_has_no_curve_and_says_so(): void
    {
        $this->editor()
            ->set('viewSettings.metric', 'turn_gini')
            ->set('viewSettings.display', 'trajectory')
            ->assertSee(__('comparisons.results.no_trajectory'));
    }

    public function test_the_turn_limit_reaches_the_result(): void
    {
        $this->editor()->set('viewSettings.turn_limit', '2')->assertSee('100,0 %');

        $this->assertSame(2, $this->comparison->fresh()->view['turn_limit']);
    }

    public function test_an_empty_label_falls_back_to_the_default_name(): void
    {
        $this->comparison->update(['series' => [['label' => '', 'projects' => ['pipeline' => 'ScorePipeline'], 'reference' => ['type' => 'seat', 'seat' => 1]]]]);

        $component = $this->editor()->assertSee(__('comparisons.series.default_label', ['n' => 1]));
        $this->assertSame(__('comparisons.series.default_label', ['n' => 1]), $component->viewData('lines')[0]['label']);

        $component->set('viewSettings.display', 'bars');
        $this->assertSame(__('comparisons.series.default_label', ['n' => 1]), $component->viewData('bars')[0]['label']);
    }
}
