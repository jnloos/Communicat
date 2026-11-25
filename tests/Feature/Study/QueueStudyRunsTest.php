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
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
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

        $this->artisan('study:queue', ['projects' => [$project->id]])
            ->expectsTable(...$this->counts(queued: 1, alreadyQueued: 0))
            ->assertSuccessful();
        $this->jobRow($project->id); // what the database queue writes, which Queue::fake() does not
        $this->artisan('study:queue', ['projects' => [$project->id]])
            ->expectsTable(...$this->counts(queued: 0, alreadyQueued: 1))
            ->assertSuccessful();

        Queue::assertPushed(RunStudyProject::class, 1);
    }

    /** A job failed while being reserved keeps its unique lock but has no row: its chain is gone. */
    public function test_a_unique_lock_without_a_job_row_is_stale_and_released(): void
    {
        $project = Project::factory()->create(['turn_budget' => 30]);
        (new UniqueLock(app(Repository::class)))->acquire(new RunStudyProject($project->id));

        $this->artisan('study:queue', ['projects' => [$project->id]])
            ->expectsOutputToContain("Run {$project->id}: released a stale unique lock")
            ->expectsTable(...$this->counts(queued: 1, alreadyQueued: 0))
            ->assertSuccessful();

        Queue::assertPushed(RunStudyProject::class, 1);
    }

    public function test_a_unique_lock_with_a_job_row_is_really_queued(): void
    {
        $project = Project::factory()->create(['turn_budget' => 30]);
        (new UniqueLock(app(Repository::class)))->acquire(new RunStudyProject($project->id));
        $this->jobRow($project->id);

        $this->artisan('study:queue', ['projects' => [$project->id]])
            ->expectsTable(...$this->counts(queued: 0, alreadyQueued: 1))
            ->assertSuccessful();

        Queue::assertNothingPushed();
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

    /** @return array{0: array<string>, 1: list<list<int|string>>} headers and rows of the summary table */
    private function counts(int $queued, int $alreadyQueued): array
    {
        return [['', ''], [
            ['Queued', $queued],
            ['Already queued', $alreadyQueued],
            ['Already finished', 0],
            ['Skipped, superseded', 0],
            ['Skipped, no budget', 0],
            ['Order', 'by id'],
        ]];
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

    private function jobRow(int $projectId): void
    {
        DB::table('jobs')->insert([
            'queue' => 'study', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
            'payload' => '{"data":{"command":"O:28:\"App\\\\Study\\\\Jobs\\\\RunStudyProject\":1:{s:9:\"projectId\";i:'.$projectId.';}"}}',
        ]);
    }
}
