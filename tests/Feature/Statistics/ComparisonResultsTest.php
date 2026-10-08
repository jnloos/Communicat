<?php

namespace Tests\Feature\Statistics;

use App\Discussion\Values\SeriesColor;
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
            ->assertSee('75.0 %')
            ->assertSee('Zelle 1');
    }

    public function test_the_bars_carry_mean_and_span(): void
    {
        $component = $this->editor()->set('viewSettings.display', 'bars');

        $group = $component->viewData('barGroups')[0];
        $bar = $group['rows'][0];
        $this->assertSame(0.75, $bar['mean']);
        $this->assertSame(0.75, $bar['min']);
        $this->assertSame(0.75, $bar['max']);
        $this->assertSame(1.0, $component->viewData('barScale'));
        $this->assertSame('indigo-500', (string) $group['color']);
        $component->assertSee('75.0 %')
            ->assertSee('75.0–75.0 % · n = 1')
            ->assertSee(__('comparisons.results.bar_text', ['mean' => '75.0 %', 'range' => '75.0–75.0 %', 'n' => 1]));
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

    public function test_gini_has_no_curve_and_falls_back_to_the_table(): void
    {
        $this->editor()
            ->set('viewSettings.metric', 'turn_gini')
            ->set('viewSettings.display', 'trajectory')
            ->assertSet('viewSettings.display', 'table')
            ->assertSee(__('comparisons.results.no_trajectory'));

        $this->assertSame('table', $this->comparison->fresh()->view['display']);
    }

    public function test_switching_to_gini_from_the_curve_falls_back_to_the_table(): void
    {
        $this->editor()
            ->set('viewSettings.display', 'trajectory')
            ->set('viewSettings.metric', 'word_gini')
            ->assertSet('viewSettings.display', 'table');
    }

    public function test_the_turn_limit_reaches_the_result(): void
    {
        $this->editor()->set('viewSettings.turn_limit', '2')->assertSee('100.0 %');

        $this->assertSame(2, $this->comparison->fresh()->view['turn_limit']);
    }

    public function test_an_empty_label_falls_back_to_the_default_name(): void
    {
        $this->comparison->update(['series' => [['label' => '', 'projects' => ['pipeline' => 'ScorePipeline'], 'reference' => ['type' => 'seat', 'seat' => 1]]]]);

        $component = $this->editor()->assertSee(__('comparisons.series.default_label', ['n' => 1]));
        $this->assertSame(__('comparisons.series.default_label', ['n' => 1]), $component->viewData('lines')[0]['label']);

        $component->set('viewSettings.display', 'bars');
        $this->assertSame(__('comparisons.series.default_label', ['n' => 1]), $component->viewData('barGroups')[0]['label']);
    }

    public function test_an_empty_label_never_repeats_a_default_name_in_use(): void
    {
        $series = $this->comparison->series[0];
        $this->comparison->update(['series' => [
            ['label' => __('comparisons.series.default_label', ['n' => 1])] + $series,
            ['label' => ''] + $series,
        ]]);

        $this->assertSame(
            [__('comparisons.series.default_label', ['n' => 1]), __('comparisons.series.default_label', ['n' => 2])],
            array_column($this->editor()->viewData('lines'), 'label'),
        );
    }

    public function test_the_tooltip_carries_formatted_values_and_names_each_n(): void
    {
        $component = $this->editor()->set('viewSettings.display', 'trajectory');
        $rows = $component->viewData('trajectoryRows');

        $this->assertSame('75.0 %', $rows[3]['t0']);
        $component->assertSee(__('comparisons.results.n_of', ['label' => 'Sitz eins']));
    }

    public function test_a_line_that_ends_early_shows_a_dash_and_zero_runs(): void
    {
        $short = Project::factory()->create(['user_id' => $this->user->id, 'pipeline' => 'RoundRobinPipeline']);
        $speaker = Expert::factory()->create();
        $short->addContributingExpert($speaker);
        foreach ([1, 2] as $turn) {
            JobLog::create([
                'job_class' => 'App\Discussion\TurnRunner', 'project_id' => $short->id, 'status' => 'success',
                'started_at' => now(), 'turn_index' => $turn, 'expert_id' => $speaker->id, 'words' => 10,
            ]);
        }
        $this->comparison->update(['series' => [
            ...$this->comparison->series,
            ['label' => 'Kurz', 'projects' => ['pipeline' => 'RoundRobinPipeline'], 'reference' => ['type' => 'seat', 'seat' => 1]],
        ]]);

        $rows = $this->editor()->set('viewSettings.display', 'trajectory')->viewData('trajectoryRows');

        $this->assertArrayNotHasKey('l1', $rows[3]);
        $this->assertSame('—', $rows[3]['t1']);
        $this->assertSame(0, $rows[3]['n1']);
    }

    public function test_rolling_shows_its_window(): void
    {
        $window = (int) config('discussion.share_window');

        $this->editor()
            ->set('viewSettings.display', 'trajectory')
            ->assertDontSee(trans_choice('statistics.trajectory.rolling_hint', $window, ['count' => $window]))
            ->set('viewSettings.trajectory_mode', 'rolling')
            ->assertSee(trans_choice('statistics.trajectory.rolling_hint', $window, ['count' => $window]));
    }

    public function test_gini_moves_its_hint_into_the_reference_and_disables_it(): void
    {
        $component = $this->editor()
            ->assertDontSee(__('comparisons.reference.run_level_hint'))
            ->set('viewSettings.metric', 'turn_gini')
            ->assertSee(__('comparisons.reference.run_level_hint'))
            ->assertDontSee(__('comparisons.reference.shared_hint'));

        $this->assertMatchesRegularExpression('/<ui-select[^>]*wire:model\.live="series\.0\.reference\.type"[^>]*disabled/s', $component->html());
    }

    public function test_more_lines_than_colours_says_so(): void
    {
        $this->editor()->assertDontSee(__('comparisons.results.palette_repeats'));

        $this->comparison->update(['series' => array_fill(0, count(SeriesColor::HUES) + 1, $this->comparison->series[0])]);

        $this->editor()->assertSee(__('comparisons.results.palette_repeats'));
    }

    public function test_a_stored_colour_reaches_every_display(): void
    {
        $this->comparison->update(['series' => [[...$this->comparison->series[0], 'color' => 'teal-300']]]);

        $component = $this->editor()->assertSee('--color-teal-300', false);
        $this->assertSame('teal-300', (string) $component->set('viewSettings.display', 'bars')->viewData('barGroups')[0]['color']);
        $component->assertSee('--color-teal-300', false);
        $this->assertSame('teal', $component->set('viewSettings.display', 'pie')->viewData('pies')[0]['slices'][0]['color']);
        $component->assertSee('--color-teal-300', false);
        $this->assertSame('teal-300', (string) $component->viewData('lines')[0]['lineColor'], 'a one-line series draws its curve in its colour');
    }

    public function test_bars_mark_the_equal_share_when_every_run_has_the_same_group_size(): void
    {
        $component = $this->editor()->set('viewSettings.display', 'bars');

        $this->assertSame(0.5, $component->viewData('equalShare'));
        $component->assertSee(__('comparisons.results.equal_share', ['share' => '50 %']))
            ->assertSee('0 %')
            ->assertSee('100 %');
    }

    public function test_mixed_group_sizes_have_no_equal_share(): void
    {
        $three = Project::factory()->create(['user_id' => $this->user->id, 'pipeline' => 'ScorePipeline']);
        $experts = Expert::factory()->count(3)->create();
        $experts->each(fn (Expert $expert) => $three->addContributingExpert($expert));
        JobLog::create([
            'job_class' => 'App\Discussion\TurnRunner', 'project_id' => $three->id, 'status' => 'success',
            'started_at' => now(), 'turn_index' => 1, 'expert_id' => $experts[0]->id, 'words' => 10,
        ]);

        $component = $this->editor()->set('viewSettings.display', 'bars');

        $this->assertNull($component->viewData('equalShare'));
        $component->assertDontSee(__('comparisons.results.equal_share', ['share' => '50 %']));
    }

    public function test_a_measure_that_is_no_share_has_no_equal_share(): void
    {
        $this->assertNull($this->editor()->set('viewSettings.display', 'bars')->set('viewSettings.metric', 'words_per_turn')->viewData('equalShare'));
    }

    public function test_the_lines_of_one_series_share_its_colour(): void
    {
        $component = $this->editor()
            ->set('series.0.reference.type', 'each')
            ->set('viewSettings.display', 'bars');

        $groups = $component->viewData('barGroups');
        $this->assertCount(1, $groups);
        $this->assertSame('indigo-500', (string) $groups[0]['color']);
        $this->assertSame([__('comparisons.results.seat', ['seat' => 1]), __('comparisons.results.seat', ['seat' => 2])], array_column($groups[0]['rows'], 'label'));

        $lines = $component->viewData('lines');
        $this->assertSame(['indigo-500', 'indigo-500'], array_map('strval', array_column($lines, 'color')));
        $this->assertNotSame($lines[0]['lineColor'], $lines[1]['lineColor']);
    }

    public function test_a_pie_of_every_seat_sums_to_a_whole(): void
    {
        $pies = $this->editor()
            ->set('series.0.reference.type', 'each')
            ->set('viewSettings.display', 'pie')
            ->viewData('pies');

        $this->assertCount(1, $pies);
        $this->assertSame(1, $pies[0]['n']);
        $this->assertSame(['tint-0', 'tint-1'], array_column($pies[0]['slices'], 'id'));
        $this->assertEqualsWithDelta(100.0, array_sum(array_column($pies[0]['slices'], 'value')), 0.01);
        $this->assertSame(['75.0 %', '25.0 %'], array_column($pies[0]['slices'], 'share'));
        $this->assertSame(['indigo', 'indigo'], array_column($pies[0]['slices'], 'color'));
    }

    public function test_a_pie_of_one_reference_shows_the_group_and_the_others(): void
    {
        $component = $this->editor()->set('viewSettings.display', 'pie');
        $slices = $component->viewData('pies')[0]['slices'];

        $this->assertSame(['Sitz eins', __('comparisons.results.others')], array_column($slices, 'name'));
        $this->assertSame([75.0, 25.0], array_column($slices, 'value'));
        $this->assertSame(['tint-0', 'others'], array_column($slices, 'id'));
        $component->assertSee('n = 1');
    }

    public function test_words_per_turn_and_gini_have_no_pie(): void
    {
        foreach (['words_per_turn', 'turn_gini'] as $metric) {
            $component = $this->editor()->set('viewSettings.display', 'pie')->set('viewSettings.metric', $metric);

            $this->assertSame('table', $this->comparison->fresh()->view['display']);
            $this->assertSame([], $component->viewData('pies'));
            $this->assertMatchesRegularExpression('/value="pie"[^>]*disabled/s', $component->html());
        }
    }

    public function test_the_shared_hint_only_shows_for_a_tag_reference(): void
    {
        $this->editor()
            ->assertDontSee(__('comparisons.reference.shared_hint'))
            ->set('series.0.reference.type', 'persona_tags')
            ->assertSee(__('comparisons.reference.shared_hint'));
    }
}
