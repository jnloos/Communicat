<?php

namespace Tests\Feature\Study;

use App\Livewire\Study\ProgressPage;
use App\Models\StudyEvent;
use App\Models\User;
use App\Services\Study\Control\PauseFlag;
use App\Services\Study\Control\PauseSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class ProgressPageControlsTest extends TestCase
{
    use BuildsStudyCells;
    use RefreshDatabase;
    use UsesTemporaryPauseFile;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($this->admin);
    }

    public function test_the_callout_shows_reason_and_time_and_the_resume_button(): void
    {
        $this->travelTo('2026-11-16 14:03:00');
        $this->cell(spoken: 1);
        app(PauseFlag::class)->pause('circuit_breaker', 'streak', PauseSource::Breaker);

        Livewire::test(ProgressPage::class)
            ->assertSee('data-pause-callout', false)
            ->assertSee('14:03')
            ->assertSee('circuit_breaker')
            ->assertSee('data-resume', false)
            ->assertDontSee('data-pause"', false);
    }

    public function test_a_running_study_shows_the_running_mark_and_the_pause_button(): void
    {
        $this->cell(spoken: 1);

        Livewire::test(ProgressPage::class)
            ->assertSee('data-running', false)
            ->assertSee('data-pause', false)
            ->assertDontSee('data-pause-callout', false);
    }

    public function test_the_running_mark_shows_even_without_open_turns_and_never_while_paused(): void
    {
        Livewire::test(ProgressPage::class)
            ->assertSee('data-running', false)
            ->assertSee(__('study.progress.running_idle'));

        app(PauseFlag::class)->pause('manual', 'manual', PauseSource::Manual);

        Livewire::test(ProgressPage::class)->assertDontSee('data-running', false);
    }

    public function test_the_pause_button_parks_the_action_and_only_confirming_pauses(): void
    {
        $this->cell(spoken: 2);
        $this->cell();
        $page = Livewire::test(ProgressPage::class)->call('askToPause');

        $page->assertSet('pendingMethod', 'pauseStudy');
        $page->assertDispatched('open-confirm', fn ($name, $params) => $params['label'] === 'Pause' && $params['variant'] === 'danger');
        $this->assertFalse(app(PauseFlag::class)->active());
        $this->assertStringContainsString('(1 runs, 2 turns left)', $page->get('confirmMessage'));

        $page->call('executeConfirmed');

        $state = app(PauseFlag::class)->state();
        $this->assertSame('manual', $state->reason);
        $this->assertSame(PauseSource::Manual, $state->source);
        $this->assertSame(1, StudyEvent::where('kind', 'manual')->count());
    }

    public function test_resume_lifts_the_pause_after_confirmation(): void
    {
        app(PauseFlag::class)->pause('lunch', 'manual', PauseSource::Manual);

        Livewire::test(ProgressPage::class)->call('askToResume')->assertSet('pendingMethod', 'resumeStudy')
            ->assertDispatched('open-confirm', fn ($name, $params) => $params['label'] === 'Resume' && $params['variant'] === 'primary')->call('executeConfirmed');

        $this->assertFalse(app(PauseFlag::class)->active());
        $this->assertSame(1, StudyEvent::where('kind', 'manual')->count());
    }

    public function test_a_non_admin_cannot_pause_even_through_the_confirmation(): void
    {
        $page = Livewire::test(ProgressPage::class)->call('askToPause');
        $this->actingAs(User::factory()->create());

        $page->call('executeConfirmed')->assertForbidden();

        $this->assertFalse(app(PauseFlag::class)->active());
    }

    public function test_a_pause_file_that_cannot_be_written_shows_the_message_instead_of_a_500(): void
    {
        $path = $this->unwritableDirectory().'/pause.json';
        config(['study.pause_file' => $path]);

        Livewire::test(ProgressPage::class)->call('askToPause')->call('executeConfirmed')
            ->assertOk()->assertSet('controlError', "Could not write the pause file {$path}.")->assertSee('data-control-error', false);
    }

    public function test_a_pause_file_that_cannot_be_removed_shows_the_message_instead_of_a_500(): void
    {
        $directory = $this->unwritableDirectory();
        chmod($directory, 0700);
        config(['study.pause_file' => $directory.'/pause.json']);
        app(PauseFlag::class)->pause('lunch', 'manual', PauseSource::Manual);
        chmod($directory, 0500);

        Livewire::test(ProgressPage::class)->call('askToResume')->call('executeConfirmed')
            ->assertOk()->assertSet('controlError', "Could not remove the pause file {$directory}/pause.json.");

        $this->assertTrue(app(PauseFlag::class)->active());
    }

    public function test_the_event_list_shows_at_most_ten_newest_first(): void
    {
        foreach (range(1, 12) as $i) {
            StudyEvent::create(['kind' => 'manual', 'level' => 'info', 'message' => "event number {$i}"]);
        }

        Livewire::test(ProgressPage::class)
            ->assertSee('event number 12')
            ->assertSee('event number 3')
            ->assertDontSee('event number 2<', false)
            ->assertSeeInOrder(['event number 12', 'event number 11']);
    }

    public function test_the_worker_signal_turns_red_when_old_with_open_runs(): void
    {
        $this->travelTo('2026-11-16 10:00:00');
        $this->cell(spoken: 1);
        Cache::put('study.worker.seen', now()->subMinutes(2)->timestamp);

        Livewire::test(ProgressPage::class)->assertSee('data-worker-signal', false)->assertDontSee('data-worker-stale', false);

        Cache::put('study.worker.seen', now()->subMinutes(11)->timestamp);

        Livewire::test(ProgressPage::class)->assertSee('data-worker-stale', false);
    }
}
