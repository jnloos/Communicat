<?php

namespace Tests\Feature\Study\Planning;

use App\Jobs\ApplyWave;
use App\Livewire\Study\PlanWavePage;
use App\Models\Project;
use App\Models\StudyWave;
use App\Models\User;
use App\Services\Study\Planning\Manifest;
use App\Services\Study\Planning\WavePlanning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class PlanWavePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admins_reach_the_page_and_the_progress_page_links_to_it(): void
    {
        $this->actingAs(User::factory()->create())->get('/study/plan')->assertForbidden();

        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get('/study/plan')->assertOk()->assertSee(__('study.plan.heading'));
        $this->get('/study')->assertSee(route('study.plan'));
    }

    public function test_an_upload_shows_the_preview_and_its_prompt(): void
    {
        $this->asAdmin();

        $this->page()
            ->call('preview')
            ->assertHasNoErrors()
            ->assertSee(__('study.plan.runs'))
            ->assertSee('ScorePipeline')
            ->assertSee('=== ABOUT YOU ===')
            ->assertSee('Should the village library open on Sundays?');

        $this->assertSame(0, Project::count());
    }

    public function test_a_study_folder_is_the_default_source_and_previews_like_an_upload(): void
    {
        $this->asAdmin();

        Livewire::test(PlanWavePage::class)
            ->assertSet('source', 'folder')
            ->assertSet('folder', 'main')
            ->assertSee('probegptsix')
            ->set('wave', 1)->set('from', 1)->set('to', 1)
            ->call('preview')
            ->assertHasNoErrors()
            ->assertSee('Should the community garden replace half of its individual plots with a shared orchard?');

        $this->assertSame(0, Project::count());
    }

    public function test_only_a_listed_folder_is_read(): void
    {
        $this->asAdmin();

        Livewire::test(PlanWavePage::class)
            ->set('folder', '../../../.env')->set('wave', 1)->set('from', 1)->set('to', 1)
            ->call('preview')
            ->assertHasErrors('folder');
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
        $page->assertSee(__('study.plan.applying', ['done' => 0, 'total' => 64]));
    }

    public function test_the_progress_counts_the_written_runs_until_the_wave_is_applied(): void
    {
        $this->asAdmin();

        // The sync queue writes the wave inside the request.
        $page = $this->page()->call('preview')->call('confirmApply')->call('executeConfirmed');

        $this->assertNotNull(StudyWave::sole()->applied_at);
        $this->assertSame(64, StudyWave::sole()->writtenRuns());
        $page->assertSee(__('study.plan.applied', ['total' => 64]));
    }

    public function test_confirming_refuses_when_the_form_changed_since_the_preview(): void
    {
        Queue::fake();
        $this->asAdmin();

        $page = $this->page('probe-manifest.json')->set('to', 2)->call('preview')->set('to', 3)
            ->call('confirmApply')->call('executeConfirmed');

        $this->assertSame(0, StudyWave::count());
        Queue::assertNothingPushed();
        $page->assertSet('overview', [])->assertHasErrors('manifest');
    }

    public function test_confirming_refuses_when_the_upload_changed_since_the_preview(): void
    {
        Queue::fake();
        $this->asAdmin();

        $this->page()->call('preview')
            ->set('manifest', UploadedFile::fake()->createWithContent('probe.json', ManifestTest::fixture('probe-manifest.json')))
            ->call('confirmApply')->call('executeConfirmed');

        $this->assertSame(0, StudyWave::count());
        Queue::assertNothingPushed();
    }

    public function test_a_failed_apply_is_shown_and_cleared_by_a_successful_retry(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);
        $wave = StudyWave::record(Manifest::fromJson(ManifestTest::fixture('tiny-manifest.json')), 1, 1, 1, $admin->id);

        (new ApplyWave($wave->id))->failed(new RuntimeException('Wave refused: nope'));
        Livewire::withQueryParams(['applying' => $wave->id])->test(PlanWavePage::class)
            ->assertSee('Wave refused: nope')
            ->assertDontSee('wire:poll', escape: false);

        (new ApplyWave($wave->id))->handle(app(WavePlanning::class));
        $this->assertNull($wave->fresh()->failed_at);
        $this->assertNull($wave->fresh()->failed_reason);
        $this->assertNotNull($wave->fresh()->applied_at);
    }

    private function page(string $fixture = 'tiny-manifest.json'): mixed
    {
        return Livewire::test(PlanWavePage::class)
            ->set('source', 'upload')
            ->set('manifest', UploadedFile::fake()->createWithContent($fixture, ManifestTest::fixture($fixture)))
            ->set('wave', 1)
            ->set('from', 1)
            ->set('to', 1);
    }

    private function asAdmin(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }
}
