<?php

namespace Tests\Feature\Study\Control;

use App\Discussion\TurnRunner;
use App\Discussion\Values\JobStatus;
use App\Models\JobLog;
use App\Models\Project;
use App\Study\Control\RunHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RunHealthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Rows oldest first: s = spoken (success with words), f = failed without words,
     * F = failed after speaking (Summarize broke), i = interrupted, j = jev_outage (success, no words),
     * a = abandoned (closed by the next job after a dead worker or a timeout).
     */
    #[DataProvider('chains')]
    public function test_it_counts_the_chains_from_the_newest_row(string $rows, int $failed, int $abandoned, int $stalled): void
    {
        $project = Project::factory()->create();
        foreach (str_split($rows) as $row) {
            $this->log($project, $row);
        }

        $health = RunHealth::of($project->id);

        $this->assertSame($failed, $health->failedInARow);
        $this->assertSame($abandoned, $health->abandonedInARow);
        $this->assertSame($stalled, $health->stalledInARow);
    }

    public static function chains(): array
    {
        return [
            'no turn yet' => ['', 0, 0, 0],
            'last turn spoke' => ['ffs', 0, 0, 0],
            'failed in a row' => ['sfff', 3, 0, 3],
            'interrupted ends the failed chain, not the stalled one' => ['sffiff', 2, 0, 5],
            'jev outage stalls but does not fail' => ['sjj', 0, 0, 2],
            'spoke, then Summarize failed: the chains break' => ['ffFff', 2, 0, 2],
            'abandoned in a row' => ['saaa', 0, 3, 3],
            'a transient interruption ends the abandoned chain' => ['aaia', 0, 1, 4],
            'a failed turn ends the abandoned chain' => ['aafa', 0, 1, 4],
        ];
    }

    public function test_the_window_covers_the_largest_limit(): void
    {
        config(['study.run.max_failed_in_a_row' => 2, 'study.run.max_abandoned_in_a_row' => 4]);
        $project = Project::factory()->create();
        foreach (range(1, 6) as $ignored) {
            $this->log($project, 'a');
        }

        $health = RunHealth::of($project->id);

        $this->assertSame(4, $health->abandonedInARow);
        $this->assertTrue($health->exhausted());
    }

    #[DataProvider('backoffs')]
    public function test_the_backoff_doubles_per_stalled_turn_up_to_fifteen_minutes(int $stalled, int $seconds): void
    {
        $project = Project::factory()->create();
        foreach (range(1, max($stalled, 1)) as $ignored) {
            $this->log($project, $stalled === 0 ? 's' : 'i');
        }

        $this->assertSame($seconds, RunHealth::of($project->id)->backoffSeconds());
    }

    public static function backoffs(): array
    {
        return ['none stalled' => [0, 60], 'one' => [1, 60], 'two' => [2, 120], 'four' => [4, 480], 'capped' => [6, 900]];
    }

    public function test_a_run_is_exhausted_by_failed_or_abandoned_turns(): void
    {
        config(['study.run.max_failed_in_a_row' => 2, 'study.run.max_abandoned_in_a_row' => 2]);
        $failing = Project::factory()->create();
        $abandoned = Project::factory()->create();
        $this->log($failing, 'f');
        $this->log($failing, 'f');
        $this->log($abandoned, 'a');

        $this->assertTrue(RunHealth::of($failing->id)->exhausted());
        $this->assertFalse(RunHealth::of($abandoned->id)->exhausted());

        $this->log($abandoned, 'a');
        $this->assertTrue(RunHealth::of($abandoned->id)->exhausted());
    }

    /** Infrastructure never gives a run up: transient interruptions and Jev outages, however many. */
    public function test_interruptions_and_jev_outages_never_exhaust_a_run(): void
    {
        $project = Project::factory()->create();
        foreach (range(1, 30) as $turn) {
            $this->log($project, $turn % 2 === 0 ? 'i' : 'j');
        }

        $this->assertFalse(RunHealth::of($project->id)->exhausted());
    }

    private function log(Project $project, string $kind): void
    {
        [$status, $words] = match ($kind) {
            's' => [JobStatus::Success, 10],
            'f' => [JobStatus::Failed, null],
            'F' => [JobStatus::Failed, 10],
            'i' => [JobStatus::Interrupted, null],
            'j' => [JobStatus::Success, null],
            'a' => [JobStatus::Interrupted, null],
        };

        JobLog::create([
            'job_class' => TurnRunner::class,
            'project_id' => $project->id,
            'status' => $status,
            'started_at' => now(),
            'words' => $words,
            'error' => $kind === 'a' ? RunHealth::ABANDONED : null,
        ]);
    }
}
