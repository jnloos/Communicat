<?php

namespace Tests\Feature\Projects;

use App\Livewire\Projects\StatisticsFlyout;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StatisticsFlyoutTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    /** @var array<string, Expert> */
    private array $experts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());

        $this->project = Project::factory()->create();

        foreach (['Alice', 'Bob'] as $name) {
            $expert = Expert::factory()->create(['name' => $name]);
            $this->project->addContributingExpert($expert);
            $this->experts[$name] = $expert;
        }
    }

    private function turn(string $name, int $words): void
    {
        JobLog::create([
            'job_class' => 'App\Discussion\TurnRunner',
            'project_id' => $this->project->id,
            'status' => 'success',
            'started_at' => now(),
            'expert_id' => $this->experts[$name]->id,
            'words' => $words,
        ]);
    }

    public function test_it_computes_nothing_before_it_is_opened(): void
    {
        $this->turn('Alice', 10);

        Livewire::test(StatisticsFlyout::class, ['project' => $this->project])
            ->assertSet('opened', false)
            ->assertViewHas('report', fn ($report) => $report->isEmpty())
            ->assertViewHas('turnSectors', []);
    }

    public function test_opening_fills_both_charts(): void
    {
        $this->turn('Alice', 10);
        $this->turn('Bob', 30);

        Livewire::test(StatisticsFlyout::class, ['project' => $this->project])
            ->call('open')
            ->assertSet('opened', true)
            ->assertViewHas('turnSectors', fn ($sectors) => count($sectors) === 2)
            ->assertViewHas('wordSectors', fn ($sectors) => count($sectors) === 2);
    }

    /**
     * The point of the two charts: a participant must keep one colour, or the
     * eye cannot compare the left slice with the right one.
     */
    public function test_a_participant_keeps_its_colour_across_both_charts(): void
    {
        $this->turn('Alice', 10);
        $this->turn('Bob', 30);

        $component = Livewire::test(StatisticsFlyout::class, ['project' => $this->project])->call('open');

        $turnColours = collect($component->viewData('turnSectors'))->pluck('color', 'name');
        $wordColours = collect($component->viewData('wordSectors'))->pluck('color', 'name');

        $this->assertSame($turnColours->all(), $wordColours->all());
        $this->assertNotSame($turnColours['Alice'], $turnColours['Bob']);
    }

    /** Flux paints var(--color-<name>-500), so an unknown name would go unpainted. */
    public function test_the_colours_come_from_the_palette_flux_accepts(): void
    {
        $palette = ['blue', 'violet', 'emerald', 'amber', 'rose', 'cyan', 'fuchsia', 'lime',
            'orange', 'teal', 'indigo', 'pink', 'sky', 'green', 'yellow', 'red', 'purple'];

        $this->turn('Alice', 10);

        $sectors = Livewire::test(StatisticsFlyout::class, ['project' => $this->project])
            ->call('open')
            ->viewData('turnSectors');

        foreach ($sectors as $sector) {
            $this->assertContains($sector['color'], $palette);
        }
    }

    public function test_a_participant_who_never_spoke_gets_no_slice_but_stays_in_the_report(): void
    {
        $this->turn('Alice', 10);

        $component = Livewire::test(StatisticsFlyout::class, ['project' => $this->project])->call('open');

        $this->assertCount(1, $component->viewData('turnSectors'));
        $this->assertCount(2, $component->viewData('report')->speakers);
    }

    public function test_the_tooltip_share_is_preformatted_for_the_view(): void
    {
        $this->turn('Alice', 10);
        $this->turn('Alice', 10);
        $this->turn('Bob', 10);

        $sectors = collect(
            Livewire::test(StatisticsFlyout::class, ['project' => $this->project])
                ->call('open')
                ->viewData('turnSectors')
        )->keyBy('name');

        $this->assertSame('66,7 %', $sectors['Alice']['share']);
        $this->assertSame('33,3 %', $sectors['Bob']['share']);
    }

    /**
     * MessageGenerator broadcasts MessageGenerated once per turn on the
     * project's channel. An open panel must pick that up, or the shares freeze
     * at whatever they were when it was opened.
     */
    public function test_an_open_panel_picks_up_the_next_turn(): void
    {
        $this->turn('Alice', 10);

        $component = Livewire::test(StatisticsFlyout::class, ['project' => $this->project])->call('open');
        $this->assertSame(1, $component->viewData('report')->spokenTurns);

        $this->turn('Bob', 30);
        $component->dispatch("echo-private:projects.{$this->project->id},.MessageGenerated");

        $this->assertSame(2, $component->viewData('report')->spokenTurns);
        $this->assertSame(40, $component->viewData('report')->totalWords);
    }

    /** A closed panel has nothing to redraw, and every turn broadcasts here. */
    public function test_a_closed_panel_ignores_the_broadcast(): void
    {
        $component = Livewire::test(StatisticsFlyout::class, ['project' => $this->project]);

        $this->turn('Alice', 10);
        $component->dispatch("echo-private:projects.{$this->project->id},.MessageGenerated");

        $this->assertSame(0, $component->viewData('report')->spokenTurns);
    }

    // --- the trajectory chart -------------------------------------------

    public function test_the_trajectory_has_one_row_per_turn_with_a_percentage_per_participant(): void
    {
        $this->turn('Alice', 10);
        $this->turn('Bob', 30);
        $this->turn('Alice', 20);

        $rows = Livewire::test(StatisticsFlyout::class, ['project' => $this->project])
            ->call('open')
            ->viewData('trajectoryRows');

        $alice = 'e'.$this->experts['Alice']->id;
        $bob = 'e'.$this->experts['Bob']->id;

        $this->assertSame([1, 2, 3], array_column($rows, 'turn'));
        $this->assertSame([100.0, 50.0, 66.7], array_column($rows, $alice));
        $this->assertSame([0.0, 50.0, 33.3], array_column($rows, $bob));
    }

    /** One participant, one colour: the lines must match the slices above them. */
    public function test_each_line_takes_its_participants_pie_colour(): void
    {
        $this->turn('Alice', 10);
        $this->turn('Bob', 30);

        $component = Livewire::test(StatisticsFlyout::class, ['project' => $this->project])->call('open');

        $pieColours = collect($component->viewData('turnSectors'))->pluck('color', 'name');
        $lineColours = collect($component->viewData('trajectoryLines'))->pluck('color', 'name');

        $this->assertSame($pieColours->all(), $lineColours->all());
    }

    /** A participant who never spoke has no slice, but a line flat at zero. */
    public function test_a_silent_participant_still_gets_a_line(): void
    {
        $this->turn('Alice', 10);

        $lines = Livewire::test(StatisticsFlyout::class, ['project' => $this->project])
            ->call('open')
            ->viewData('trajectoryLines');

        $this->assertSame(['Alice', 'Bob'], array_column($lines, 'name'));
    }

    public function test_the_toggles_switch_unit_and_mode(): void
    {
        config(['discussion.share_window' => 1]);

        $this->turn('Alice', 10);
        $this->turn('Bob', 30);

        $alice = 'e'.$this->experts['Alice']->id;
        $component = Livewire::test(StatisticsFlyout::class, ['project' => $this->project])->call('open');

        $this->assertSame(50.0, $component->viewData('trajectoryRows')[1][$alice], 'cumulative turns by default');

        $component->set('trajectoryUnit', 'words');
        $this->assertSame(25.0, $component->viewData('trajectoryRows')[1][$alice]);

        $component->set('trajectoryMode', 'rolling');
        $this->assertSame(0.0, $component->viewData('trajectoryRows')[1][$alice], 'a window of one holds only Bob');
    }

    /** The toggles are public, so the browser can send anything; that must not break the panel. */
    public function test_an_unknown_toggle_value_falls_back_to_cumulative_turns(): void
    {
        $this->turn('Alice', 10);
        $this->turn('Bob', 30);

        $rows = Livewire::test(StatisticsFlyout::class, ['project' => $this->project])
            ->call('open')
            ->set('trajectoryUnit', 'tokens')
            ->viewData('trajectoryRows');

        $this->assertSame(50.0, $rows[1]['e'.$this->experts['Alice']->id]);
    }

    public function test_the_open_panel_renders_the_line_chart(): void
    {
        $this->turn('Alice', 10);
        $this->turn('Bob', 30);

        Livewire::test(StatisticsFlyout::class, ['project' => $this->project])
            ->call('open')
            ->assertSee(__('statistics.trajectory.title'))
            ->assertSeeHtml('name="line"')
            ->assertSeeHtml('field="e'.$this->experts['Bob']->id.'"');
    }

    public function test_another_projects_turns_do_not_leak_in(): void
    {
        $other = Project::factory()->create();
        $other->addContributingExpert($this->experts['Alice']);
        JobLog::create([
            'job_class' => 'App\Discussion\TurnRunner',
            'project_id' => $other->id, 'status' => 'success', 'started_at' => now(),
            'expert_id' => $this->experts['Alice']->id, 'words' => 500,
        ]);

        $this->turn('Alice', 10);

        $report = Livewire::test(StatisticsFlyout::class, ['project' => $this->project])
            ->call('open')
            ->viewData('report');

        $this->assertSame(1, $report->spokenTurns);
        $this->assertSame(10, $report->totalWords);
    }
}
