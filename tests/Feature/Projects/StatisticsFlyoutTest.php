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
