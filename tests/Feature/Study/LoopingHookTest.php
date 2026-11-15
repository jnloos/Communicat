<?php

namespace Tests\Feature\Study;

use App\Study\Control\PauseFlag;
use App\Study\Control\PauseSource;
use App\Study\Jobs\RunStudyProject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pins the Laravel behaviour the pause relies on (spec risk 8): a Looping listener that
 * returns false makes Worker::daemon sleep instead of reserving a job. `--once` never
 * fires Looping, so these tests run the daemon with --stop-when-empty: it stops after
 * the first round that took no job.
 */
class LoopingHookTest extends TestCase
{
    use RefreshDatabase;
    use UsesTemporaryPauseFile;

    protected function setUp(): void
    {
        parent::setUp();

        config(['queue.connections.other' => ['driver' => 'database', 'table' => 'jobs', 'queue' => 'other', 'retry_after' => 90]]);
        LoopingProbe::$ran = 0;
    }

    public function test_a_paused_study_worker_takes_no_job(): void
    {
        app(PauseFlag::class)->pause('test', 'manual', PauseSource::Manual);
        LoopingProbe::dispatch()->onConnection(RunStudyProject::CONNECTION)->onQueue(RunStudyProject::CONNECTION);

        $this->work(RunStudyProject::CONNECTION);

        $this->assertSame(0, LoopingProbe::$ran);
        $this->assertSame(1, DB::table('jobs')->whereNull('reserved_at')->count());
    }

    public function test_an_unpaused_study_worker_takes_the_job(): void
    {
        LoopingProbe::dispatch()->onConnection(RunStudyProject::CONNECTION)->onQueue(RunStudyProject::CONNECTION);

        $this->work(RunStudyProject::CONNECTION);

        $this->assertSame(1, LoopingProbe::$ran);
    }

    public function test_the_pause_leaves_other_connections_alone(): void
    {
        app(PauseFlag::class)->pause('test', 'manual', PauseSource::Manual);
        LoopingProbe::dispatch()->onConnection('other')->onQueue('other');

        $this->work('other');

        $this->assertSame(1, LoopingProbe::$ran);
    }

    private function work(string $connection): void
    {
        $this->artisan('queue:work', ['connection' => $connection, '--queue' => $connection, '--stop-when-empty' => true, '--sleep' => '0.01'])
            ->assertSuccessful();
    }
}
