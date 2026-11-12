<?php

namespace Tests\Feature\Study;

use App\Discussion\Agents\SpeakAgent;
use App\Discussion\Agents\SummarizeAgent;
use App\Discussion\Agents\ThinkAgent;
use App\Discussion\TurnRunner;
use App\Discussion\Values\JobStatus;
use App\Events\JobLogged;
use App\Events\PipelineStageChanged;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Study\Jobs\RunStudyProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use RuntimeException;
use Tests\Fakes\FakeAgents;
use Tests\TestCase;

class RunStudyProjectTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PipelineStageChanged::class, JobLogged::class]);

        FakeAgents::always(ThinkAgent::class, ['thought' => 'Ich will widersprechen.']);
        FakeAgents::always(SpeakAgent::class, ['contribution' => 'Ein kurzer Beitrag.', 'addressee' => null]);
        FakeAgents::always(SummarizeAgent::class, ['summary' => 'Zusammenfassung.']);

        $this->project = Project::factory()->create(['pipeline' => 'RoundRobinPipeline', 'turn_budget' => 3]);
        $this->project->addContributingExpert(Expert::factory()->create());
        $this->project->addContributingExpert(Expert::factory()->create());
    }

    public function test_it_runs_to_the_turn_budget(): void
    {
        $job = $this->handle();

        $job->assertNotReleased();
        $job->assertNotFailed();
        $this->assertSame(3, JobLog::where('project_id', $this->project->id)->whereNotNull('words')->count());
    }

    public function test_an_infrastructure_error_puts_the_job_back_without_more_turns(): void
    {
        FakeAgents::fails(SpeakAgent::class, new ProviderConnectionException('gateway down'));

        $job = $this->handle();

        $job->assertReleased(60);
        $this->assertSame(1, JobLog::count());
        $this->assertSame(JobStatus::Interrupted, JobLog::sole()->status);
    }

    public function test_model_failures_in_a_row_give_up_the_run(): void
    {
        FakeAgents::fails(SpeakAgent::class, new RuntimeException('unreadable'));

        $job = $this->handle();

        $job->assertFailed();
        $this->assertSame(RunStudyProject::MAX_FAILED_IN_A_ROW, JobLog::where('status', JobStatus::Failed)->count());
    }

    public function test_a_turn_left_running_by_a_dead_worker_is_closed_as_interrupted(): void
    {
        $dead = JobLog::create([
            'job_class' => TurnRunner::class,
            'project_id' => $this->project->id,
            'status' => JobStatus::Running,
            'started_at' => now(),
            'turn_index' => 1,
        ]);

        $this->handle();

        $this->assertSame(JobStatus::Interrupted, $dead->fresh()->status);
    }

    public function test_it_lands_on_the_study_queue(): void
    {
        Queue::fake();

        RunStudyProject::dispatch($this->project->id);

        Queue::connection('study')->assertPushedOn('study', RunStudyProject::class);
    }

    private function handle(): RunStudyProject
    {
        $job = (new RunStudyProject($this->project->id))->withFakeQueueInteractions();
        $job->handle(app(TurnRunner::class));

        return $job;
    }
}
