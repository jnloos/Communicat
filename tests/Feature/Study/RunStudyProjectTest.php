<?php

namespace Tests\Feature\Study;

use App\Discussion\Agents\SpeakAgent;
use App\Discussion\Agents\SummarizeAgent;
use App\Discussion\Agents\ThinkAgent;
use App\Discussion\ModelDrifted;
use App\Discussion\Pipelines\UnknownPipeline;
use App\Discussion\TurnRunner;
use App\Discussion\Values\JobStatus;
use App\Events\JobLogged;
use App\Events\PipelineStageChanged;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Study\Control\BreakerStreak;
use App\Study\Control\GiveUp;
use App\Study\Control\PauseFlag;
use App\Study\Control\PauseSource;
use App\Study\Events\BreakerTripped;
use App\Study\Jobs\RunStudyProject;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fakes\FakeAgents;
use Tests\TestCase;

class RunStudyProjectTest extends TestCase
{
    use RefreshDatabase;
    use UsesTemporaryPauseFile;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PipelineStageChanged::class, JobLogged::class, BreakerTripped::class]);
        Queue::fake();

        FakeAgents::always(ThinkAgent::class, ['thought' => 'Ich will widersprechen.']);
        FakeAgents::always(SpeakAgent::class, ['contribution' => 'Ein kurzer Beitrag.', 'addressee' => null]);
        FakeAgents::always(SummarizeAgent::class, ['summary' => 'Zusammenfassung.']);

        $this->project = Project::factory()->create(['pipeline' => 'RoundRobinPipeline', 'turn_budget' => 3]);
        $this->project->addContributingExpert(Expert::factory()->create());
        $this->project->addContributingExpert(Expert::factory()->create());
    }

    public function test_one_job_speaks_one_turn_and_queues_its_successor_at_once(): void
    {
        $job = $this->handle();

        $job->assertNotReleased();
        $job->assertNotFailed();
        $this->assertSame(1, JobLog::whereNotNull('words')->count());
        $this->assertSuccessorAfter(0);
    }

    public function test_a_spent_budget_ends_the_chain(): void
    {
        $this->project->update(['turn_budget' => 1]);
        $this->pastTurns(1, JobStatus::Success, words: 5);

        $job = $this->handle();

        $job->assertNotFailed();
        $this->assertSame(1, JobLog::count());
        Queue::assertNothingPushed();
    }

    public function test_a_job_finding_the_run_locked_does_nothing(): void
    {
        Cache::lock("study-run:{$this->project->id}", 10)->get();

        $job = $this->handle();

        $job->assertNotReleased();
        $this->assertSame(0, JobLog::count());
        Queue::assertNothingPushed();
    }

    public function test_a_paused_study_puts_the_job_back_without_a_turn(): void
    {
        app(PauseFlag::class)->pause('test', 'manual', PauseSource::Manual);

        $job = $this->handle();

        $job->assertReleased(60);
        $this->assertSame(0, JobLog::count());
    }

    public function test_a_given_up_run_is_not_continued(): void
    {
        app(GiveUp::class)->mark($this->project);

        $this->handle();

        $this->assertSame(0, JobLog::count());
        Queue::assertNothingPushed();
    }

    #[DataProvider('stalls')]
    public function test_an_infrastructure_error_backs_off_by_the_stalled_turns(int $stalledBefore, int $delay): void
    {
        $this->pastTurns($stalledBefore, JobStatus::Interrupted);
        FakeAgents::fails(SpeakAgent::class, new ProviderConnectionException('gateway down'));

        $job = $this->handle();

        $job->assertNotFailed();
        $this->assertSame(JobStatus::Interrupted, JobLog::latest('id')->first()->status);
        $this->assertSuccessorAfter($delay);
    }

    public static function stalls(): array
    {
        return ['first' => [0, 60], 'third' => [2, 240], 'capped' => [6, 900]];
    }

    public function test_a_failed_turn_below_the_limit_carries_on_at_once(): void
    {
        FakeAgents::fails(SpeakAgent::class, new RuntimeException('unreadable'));

        $job = $this->handle();

        $job->assertNotFailed();
        $this->assertFalse(app(GiveUp::class)->isMarked($this->project));
        $this->assertSuccessorAfter(0);
    }

    public function test_failed_turns_up_to_the_limit_give_up_the_run(): void
    {
        $this->pastTurns(9, JobStatus::Failed);
        FakeAgents::fails(SpeakAgent::class, new RuntimeException('unreadable'));

        $job = $this->handle();

        $job->assertFailed();
        $this->assertTrue(app(GiveUp::class)->isMarked($this->project));
        Queue::assertNothingPushed();
    }

    /** The counting rule: a turn that spoke and then failed in Summarize is spoken. */
    public function test_a_turn_that_spoke_breaks_the_failed_chain(): void
    {
        $this->pastTurns(9, JobStatus::Failed);
        $this->pastTurns(1, JobStatus::Failed, words: 12);
        FakeAgents::fails(SpeakAgent::class, new RuntimeException('unreadable'));

        $job = $this->handle();

        $job->assertNotFailed();
        $this->assertFalse(app(GiveUp::class)->isMarked($this->project));
    }

    /** A run caught in an outage is a victim, not waste: a dead key must not supersede it. */
    public function test_a_breaker_trip_in_the_same_turn_keeps_the_run(): void
    {
        $this->pastTurns(9, JobStatus::Failed);
        FakeAgents::fails(SpeakAgent::class, new RuntimeException('HTTP request returned status code 401: invalid key'));

        $job = $this->handle();

        $job->assertNotFailed();
        $this->assertSame('auth', app(PauseFlag::class)->state()->signal);
        $this->assertFalse(app(GiveUp::class)->isMarked($this->project));
        $this->assertSuccessorAfter(0);
    }

    public function test_an_aborted_run_trips_the_breaker_and_stays_open(): void
    {
        FakeAgents::fails(SpeakAgent::class, new ModelDrifted('gpt-5-mini', 'gpt-5'));

        $job = $this->handle();

        $job->assertNotFailed();
        $this->assertSame('abort', app(PauseFlag::class)->state()->signal);
        Event::assertDispatched(BreakerTripped::class, fn (BreakerTripped $event) => $event->projectId === $this->project->id);
        $this->assertFalse(app(GiveUp::class)->isMarked($this->project));
        $this->assertSuccessorAfter(60);
    }

    public function test_an_unexpected_exception_counts_and_fails_the_job(): void
    {
        $this->project->update(['pipeline' => 'NoSuchPipeline']);
        $job = (new RunStudyProject($this->project->id))->withFakeQueueInteractions();

        try {
            $this->perform($job);
            $this->fail('The exception was swallowed.');
        } catch (UnknownPipeline $e) {
            $this->assertStringContainsString('Unknown pipeline', $e->getMessage());
        }

        $job->assertFailed();
        $this->assertSame(1, app(BreakerStreak::class)->count());
        Queue::assertNothingPushed();
    }

    public function test_a_spoken_turn_resets_the_streak(): void
    {
        app(BreakerStreak::class)->increment();

        $this->handle();

        $this->assertSame(0, app(BreakerStreak::class)->count());
    }

    public function test_a_turn_left_running_by_a_dead_worker_is_closed_and_counts_as_stalled(): void
    {
        $dead = JobLog::create([
            'job_class' => TurnRunner::class,
            'project_id' => $this->project->id,
            'status' => JobStatus::Running,
            'started_at' => now(),
            'turn_index' => 1,
        ]);
        FakeAgents::fails(SpeakAgent::class, new ProviderConnectionException('gateway down'));

        $this->handle();

        $this->assertSame(JobStatus::Interrupted, $dead->fresh()->status);
        $this->assertSuccessorAfter(120);
    }

    public function test_it_lands_on_the_study_queue_once_per_run(): void
    {
        RunStudyProject::dispatch($this->project->id);
        RunStudyProject::dispatch($this->project->id);

        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, new RunStudyProject($this->project->id));
        Queue::connection('study')->assertPushedOn('study', RunStudyProject::class);
        Queue::assertPushed(RunStudyProject::class, 1);
    }

    private function handle(): RunStudyProject
    {
        $job = (new RunStudyProject($this->project->id))->withFakeQueueInteractions();
        $this->perform($job);

        return $job;
    }

    private function perform(RunStudyProject $job): void
    {
        app()->call([$job, 'handle']);
    }

    private function assertSuccessorAfter(int $seconds): void
    {
        Queue::assertPushed(RunStudyProject::class, 1);
        Queue::assertPushed(RunStudyProject::class, fn (RunStudyProject $job) => $job->projectId === $this->project->id && (int) $job->delay === $seconds);
    }

    private function pastTurns(int $count, JobStatus $status, ?int $words = null): void
    {
        for ($i = 0; $i < $count; $i++) {
            JobLog::create([
                'job_class' => TurnRunner::class,
                'project_id' => $this->project->id,
                'status' => $status,
                'started_at' => now(),
                'finished_at' => now(),
                'turn_index' => (int) JobLog::max('turn_index') + 1,
                'expert_id' => $words === null ? null : $this->project->contributingExperts()->first()->id,
                'words' => $words,
            ]);
        }
    }
}
