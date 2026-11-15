<?php

namespace Tests\Feature\Study\Planning;

use App\Models\Project;
use App\Models\User;
use App\Study\Jobs\ApplyWave;
use App\Study\Livewire\PlanWavePage;
use App\Study\Models\StudyWave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class PlanWavePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_reach_the_page_and_the_progress_page_links_to_it(): void
    {
        $this->actingAs(User::factory()->create())->get('/study/plan')->assertForbidden();

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/study/plan')->assertOk()->assertSee(__('study::plan.heading'));
        $this->get('/study')->assertSee(route('study.plan'));
    }

    public function test_an_upload_shows_the_preview_and_its_prompt(): void
    {
        $this->asAdmin();

        $this->page()
            ->call('preview')
            ->assertHasNoErrors()
            ->assertSee(__('study::plan.runs'))
            ->assertSee('ScorePipeline')
            ->assertSee('=== ABOUT YOU ===')
            ->assertSee('Should the village library open on Sundays?');

        $this->assertSame(0, Project::count());
    }

    public function test_a_refused_wave_shows_why_and_cannot_be_applied(): void
    {
        $this->asAdmin();

        $this->page()->set('from', 2)->set('to', 2)->call('preview')
            ->assertSee('scenarios 1 to 1')
            ->call('confirmApply')
            ->assertNotDispatched('open-confirm');
    }

    public function test_confirming_records_the_wave_and_queues_it(): void
    {
        Queue::fake();
        $this->asAdmin();

        $page = $this->page()->call('preview')->call('confirmApply')->assertDispatched('open-confirm')->call('executeConfirmed');

        $wave = StudyWave::sole();
        $this->assertSame(['tiny', 1, 1, 1], [$wave->study, $wave->number, $wave->first_scenario, $wave->last_scenario]);
        Queue::assertPushed(ApplyWave::class, fn (ApplyWave $job) => $job->waveId === $wave->id);
        $page->assertSee(__('study::plan.applying', ['done' => 0, 'total' => 64]));
    }

    public function test_the_progress_counts_the_written_runs_until_the_wave_is_applied(): void
    {
        $this->asAdmin();

        // The sync queue writes the wave inside the request.
        $page = $this->page()->call('preview')->call('confirmApply')->call('executeConfirmed');

        $this->assertNotNull(StudyWave::sole()->applied_at);
        $this->assertSame(64, StudyWave::sole()->writtenRuns());
        $page->assertSee(__('study::plan.applied', ['total' => 64]));
    }

    private function page(): mixed
    {
        return Livewire::test(PlanWavePage::class)
            ->set('manifest', UploadedFile::fake()->createWithContent('tiny.json', ManifestTest::fixture('tiny-manifest.json')))
            ->set('wave', 1)
            ->set('from', 1)
            ->set('to', 1);
    }

    private function asAdmin(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }
}
