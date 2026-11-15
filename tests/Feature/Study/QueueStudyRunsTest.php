<?php

namespace Tests\Feature\Study;

use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\User;
use App\Study\Control\GiveUp;
use App\Study\Control\PauseFlag;
use App\Study\Control\PauseSource;
use App\Study\Jobs\RunStudyProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class QueueStudyRunsTest extends TestCase
{
    use RefreshDatabase;
    use UsesTemporaryPauseFile;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_it_queues_the_open_runs_and_skips_finished_and_unbudgeted_ones(): void
    {
        $open = Project::factory()->create(['turn_budget' => 2]);
        $finished = Project::factory()->create(['turn_budget' => 1]);
        $endless = Project::factory()->create(['turn_budget' => null]);
        $this->spokenTurn($finished);

        $this->artisan('study:queue', ['projects' => [$open->id, $finished->id, $endless->id]])->assertSuccessful();

        Queue::assertPushed(RunStudyProject::class, 1);
        Queue::assertPushed(RunStudyProject::class, fn (RunStudyProject $job) => $job->projectId === $open->id);
    }

    public function test_it_skips_given_up_runs(): void
    {
        $open = Project::factory()->create(['turn_budget' => 30]);
        $givenUp = Project::factory()->create(['turn_budget' => 30]);
        app(GiveUp::class)->mark($givenUp);

        $this->artisan('study:queue', ['projects' => [$open->id, $givenUp->id]])
            ->expectsOutputToContain('Skipped, superseded')
            ->assertSuccessful();

        Queue::assertPushed(RunStudyProject::class, 1);
        Queue::assertPushed(RunStudyProject::class, fn (RunStudyProject $job) => $job->projectId === $open->id);
    }

    /** ShouldBeUniqueUntilProcessing: a second study:queue must not start a second chain. */
    public function test_queuing_twice_gives_one_job_per_run(): void
    {
        $project = Project::factory()->create(['turn_budget' => 30]);

        $this->artisan('study:queue', ['projects' => [$project->id]])->assertSuccessful();
        $this->artisan('study:queue', ['projects' => [$project->id]])->assertSuccessful();

        Queue::assertPushed(RunStudyProject::class, 1);
    }

    public function test_it_queues_while_paused_and_says_so(): void
    {
        $project = Project::factory()->create(['turn_budget' => 30]);
        app(PauseFlag::class)->pause('Key revoked', 'auth', PauseSource::Breaker);

        $this->artisan('study:queue', ['projects' => [$project->id]])
            ->expectsOutputToContain('Study is paused')
            ->assertSuccessful();

        Queue::assertPushed(RunStudyProject::class, 1);
    }

    public function test_it_queues_a_group(): void
    {
        $user = User::factory()->create();
        $group = $user->projectGroups()->create(['name' => 'Batch 1', 'position' => 0]);
        $filed = Project::factory()->create(['user_id' => $user->id, 'turn_budget' => 30]);
        $filed->addContributingUser($user);
        DB::table('project_contributors')->where('project_id', $filed->id)->update(['project_group_id' => $group->id]);
        Project::factory()->create(['turn_budget' => 30]);

        $this->artisan('study:queue', ['--group' => $group->id])->assertSuccessful();

        Queue::assertPushed(RunStudyProject::class, 1);
        Queue::assertPushed(RunStudyProject::class, fn (RunStudyProject $job) => $job->projectId === $filed->id);
    }

    /** The order seed is part of the batch's record: the same seed must give the same order. */
    public function test_the_order_seed_shuffles_reproducibly(): void
    {
        $ids = Project::factory()->count(12)->create(['turn_budget' => 30])->modelKeys();

        $first = $this->queuedOrder($ids, 7);
        $second = $this->queuedOrder($ids, 7);

        $this->assertSame($first, $second);
        $this->assertNotSame($ids, $first);
        $this->assertEqualsCanonicalizing($ids, $first);
    }

    public function test_it_refuses_an_unknown_project(): void
    {
        $this->artisan('study:queue', ['projects' => [999]])->assertFailed();

        Queue::assertNothingPushed();
    }

    public function test_it_refuses_to_queue_nothing(): void
    {
        $this->artisan('study:queue')->assertFailed();
    }

    private function queuedOrder(array $ids, int $seed): array
    {
        Queue::fake();
        Cache::flush(); // the unique locks of the previous call
        $this->artisan('study:queue', ['projects' => $ids, '--order-seed' => $seed])->assertSuccessful();

        return Queue::pushed(RunStudyProject::class)->map(fn (RunStudyProject $job) => $job->projectId)->values()->all();
    }

    private function spokenTurn(Project $project): void
    {
        JobLog::create([
            'job_class' => 'App\Discussion\TurnRunner',
            'project_id' => $project->id,
            'status' => 'success',
            'started_at' => now(),
            'expert_id' => Expert::factory()->create()->id,
            'words' => 5,
        ]);
    }
}
