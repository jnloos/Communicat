<?php

namespace Tests\Feature\Debug;

use App\Livewire\Debug\JobDebugFlyout;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class JobDebugFlyoutTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => true]);

        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function project(string $title = 'Lauf'): Project
    {
        return Project::withoutEvents(fn () => Project::create([
            'title' => $title, 'description' => 'd', 'user_id' => $this->user->id,
        ]));
    }

    private function jobFor(Project $project, string $status = 'success'): JobLog
    {
        return JobLog::create([
            'job_class' => 'App\Discussion\TurnRunner',
            'project_id' => $project->id,
            'status' => $status,
            'started_at' => now(),
        ]);
    }

    public function test_it_shows_only_jobs_of_its_own_project(): void
    {
        $mine = $this->project('Meiner');
        $other = $this->project('Fremder');

        $ownJob = $this->jobFor($mine);
        $foreignJob = $this->jobFor($other);

        $logs = Livewire::test(JobDebugFlyout::class, ['project' => $mine])
            ->call('open')
            ->viewData('logs');

        $this->assertTrue($logs->contains('id', $ownJob->id));
        $this->assertFalse($logs->contains('id', $foreignJob->id));
    }

    public function test_it_queries_nothing_before_it_is_opened(): void
    {
        $project = $this->project();
        $this->jobFor($project);

        $logs = Livewire::test(JobDebugFlyout::class, ['project' => $project])->viewData('logs');

        $this->assertTrue($logs->isEmpty());
    }

    public function test_opening_loads_the_jobs(): void
    {
        $project = $this->project();
        $job = $this->jobFor($project);

        Livewire::test(JobDebugFlyout::class, ['project' => $project])
            ->assertSet('opened', false)
            ->call('open')
            ->assertSet('opened', true)
            ->assertViewHas('logs', fn ($logs) => $logs->contains('id', $job->id));
    }

    public function test_a_job_opens_and_closes_on_the_same_click(): void
    {
        $project = $this->project();
        $job = $this->jobFor($project);

        Livewire::test(JobDebugFlyout::class, ['project' => $project])
            ->call('open')
            ->call('selectJob', $job->id)
            ->assertSet('selectedJobId', $job->id)
            ->assertViewHas('selected', fn ($selected) => $selected?->id === $job->id)
            ->call('selectJob', $job->id)
            ->assertSet('selectedJobId', null)
            ->assertViewHas('selected', null);
    }

    public function test_a_job_of_another_project_cannot_be_selected(): void
    {
        $mine = $this->project('Meiner');
        $foreignJob = $this->jobFor($this->project('Fremder'));

        Livewire::test(JobDebugFlyout::class, ['project' => $mine])
            ->call('open')
            ->call('selectJob', $foreignJob->id)
            ->assertViewHas('selected', null);
    }

    /**
     * The flyout carries no pause control. Reading a report in peace is what the
     * standalone debug page is for; here the report belongs to the run in front
     * of you, and a control that silently detaches it from that run is a way to
     * read stale numbers without noticing.
     */
    public function test_the_flyout_offers_no_pause_control(): void
    {
        Livewire::test(JobDebugFlyout::class, ['project' => $this->project()])
            ->call('open')
            ->assertDontSee('togglePause');
    }

    /**
     * What replaced pausing: a flyout nobody has open ignores the broadcast, so a
     * running discussion does not re-query the report on every single turn.
     */
    public function test_a_closed_flyout_ignores_a_live_update(): void
    {
        $project = $this->project();
        $component = Livewire::test(JobDebugFlyout::class, ['project' => $project]);

        $this->jobFor($project);
        $component->dispatch('echo-private:debug,.JobLogUpdated');

        $this->assertTrue($component->viewData('logs')->isEmpty());
    }

    /**
     * TurnRunner broadcasts JobLogged twice per turn — once when the job opens
     * as 'running', once when it closes. An open flyout must follow along.
     */
    public function test_an_open_flyout_picks_up_a_new_job(): void
    {
        $project = $this->project();
        $this->jobFor($project);

        $component = Livewire::test(JobDebugFlyout::class, ['project' => $project])->call('open');
        $this->assertCount(1, $component->viewData('logs'));

        $this->jobFor($project, 'running');
        $component->dispatch('echo-private:debug,.JobLogUpdated');

        $this->assertCount(2, $component->viewData('logs'));
    }

    public function test_it_is_unreachable_when_debug_is_off(): void
    {
        config(['app.debug' => false]);

        Livewire::test(JobDebugFlyout::class, ['project' => $this->project()])->assertStatus(404);
    }
}
