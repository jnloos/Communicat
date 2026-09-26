<?php

namespace Tests\Feature\Discussion;

use App\Discussion\Metrics\SpeakingShares;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\SpeakerShare;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SpeakingSharesTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    /** @var array<int, Expert> */
    private array $experts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = Project::factory()->create();

        foreach (['Alice', 'Bob', 'Carol', 'Dave'] as $name) {
            $expert = Expert::factory()->create(['name' => $name]);
            $this->project->addContributingExpert($expert);
            $this->experts[$name] = $expert;
        }
    }

    /** A turn as the runner records it: words set means the turn spoke. */
    private function turn(string $name, ?int $words, string $status = 'success'): JobLog
    {
        return JobLog::create([
            'job_class' => 'App\Discussion\TurnRunner',
            'project_id' => $this->project->id,
            'status' => $status,
            'started_at' => now(),
            'expert_id' => $this->experts[$name]->id,
            'words' => $words,
            'chars' => $words === null ? null : $words * 6,
        ]);
    }

    private function report(): ShareReport
    {
        return app(SpeakingShares::class)->forProject($this->project->fresh());
    }

    private function shareOf(string $name): SpeakerShare
    {
        foreach ($this->report()->speakers as $share) {
            if ($share->name === $name) {
                return $share;
            }
        }

        $this->fail("No share row for {$name}.");
    }

    public function test_an_equal_run_gives_everyone_a_quarter_and_a_gini_of_zero(): void
    {
        foreach (['Alice', 'Bob', 'Carol', 'Dave'] as $name) {
            $this->turn($name, 10);
        }

        $report = $this->report();

        $this->assertSame(4, $report->spokenTurns);
        $this->assertSame(40, $report->totalWords);
        $this->assertSame(0.0, round($report->turnGini, 10));
        $this->assertSame(0.0, round($report->wordGini, 10));
        $this->assertSame(0.25, $this->shareOf('Alice')->turnShare);
        $this->assertSame(0.25, $this->shareOf('Alice')->wordShare);
    }

    public function test_one_speaker_holding_the_floor_reaches_the_ceiling(): void
    {
        foreach (range(1, 8) as $ignored) {
            $this->turn('Alice', 10);
        }

        $report = $this->report();

        $this->assertSame(1.0, $this->shareOf('Alice')->turnShare);
        $this->assertSame(0.0, $this->shareOf('Bob')->turnShare);
        $this->assertSame(0.75, round($report->turnGini, 10));
        $this->assertSame(0.75, $report->giniCeiling);
    }

    public function test_a_silent_expert_still_gets_a_row(): void
    {
        $this->turn('Alice', 10);

        $dave = $this->shareOf('Dave');

        $this->assertSame(0, $dave->turns);
        $this->assertSame(0, $dave->words);
        $this->assertSame(0.0, $dave->turnShare);
        $this->assertCount(4, $this->report()->speakers);
    }

    public function test_a_run_without_turns_reports_zeroes_instead_of_failing(): void
    {
        $report = $this->report();

        $this->assertTrue($report->isEmpty());
        $this->assertSame(0, $report->spokenTurns);
        $this->assertSame(0.0, $report->turnGini);
        $this->assertCount(4, $report->speakers);
    }

    /**
     * The rule that separates this from a naive count: PersistMessage saves the
     * message before Summarize runs, so a turn can speak and then fail. That
     * contribution was seen by the participants and must be counted.
     */
    public function test_a_turn_that_spoke_and_then_failed_still_counts(): void
    {
        $this->turn('Alice', 12, status: 'failed');

        $this->assertSame(1, $this->report()->spokenTurns);
        $this->assertSame(12, $this->shareOf('Alice')->words);
    }

    /** A turn that never got to speak has no words, and must not be counted. */
    public function test_a_turn_that_never_spoke_is_not_counted(): void
    {
        $this->turn('Alice', 10);
        $this->turn('Bob', null, status: 'failed');

        $this->assertSame(1, $this->report()->spokenTurns);
        $this->assertSame(0, $this->shareOf('Bob')->turns);
    }

    /** Selector and judge calls write no expert_id; they are not turns. */
    public function test_a_log_without_a_speaker_is_not_counted(): void
    {
        $this->turn('Alice', 10);
        JobLog::create([
            'job_class' => 'App\Discussion\TurnRunner',
            'project_id' => $this->project->id,
            'status' => 'success', 'started_at' => now(), 'words' => 99,
        ]);

        $this->assertSame(1, $this->report()->spokenTurns);
        $this->assertSame(10, $this->report()->totalWords);
    }

    public function test_another_project_does_not_leak_in(): void
    {
        $other = Project::factory()->create();
        $other->addContributingExpert($this->experts['Alice']);
        JobLog::create([
            'job_class' => 'App\Discussion\TurnRunner',
            'project_id' => $other->id, 'status' => 'success', 'started_at' => now(),
            'expert_id' => $this->experts['Alice']->id, 'words' => 500,
        ]);

        $this->turn('Alice', 10);

        $this->assertSame(1, $this->report()->spokenTurns);
        $this->assertSame(10, $this->report()->totalWords);
    }

    /** The two units diverge — the reason the study reports both. */
    public function test_the_two_units_can_disagree(): void
    {
        $this->turn('Alice', 5);
        $this->turn('Alice', 5);
        $this->turn('Alice', 5);
        $this->turn('Bob', 45);

        $alice = $this->shareOf('Alice');
        $bob = $this->shareOf('Bob');

        $this->assertSame(0.75, $alice->turnShare);
        $this->assertSame(0.25, round($alice->wordShare, 10));
        $this->assertSame(0.25, $bob->turnShare);
        $this->assertSame(0.75, round($bob->wordShare, 10));
        $this->assertSame(5.0, $alice->wordsPerTurn());
        $this->assertSame(45.0, $bob->wordsPerTurn());
    }

    /**
     * The research plan asks for failure rates to be reported rather than
     * quietly filtered. A turn that failed before speaking leaves no trace in
     * the shares, so the report has to carry it separately.
     */
    public function test_turns_that_failed_before_speaking_are_reported(): void
    {
        $this->turn('Alice', 10);
        $this->turn('Bob', null, status: 'failed');
        $this->turn('Bob', null, status: 'failed');
        $this->turn('Carol', 12, status: 'failed');

        $report = $this->report();

        $this->assertSame(2, $report->spokenTurns, 'the one that spoke before failing counts as spoken');
        $this->assertSame(2, $report->failedTurns);
    }

    public function test_speakers_come_back_in_seat_order(): void
    {
        $names = array_map(fn (SpeakerShare $s) => $s->name, $this->report()->speakers);

        $this->assertSame(['Alice', 'Bob', 'Carol', 'Dave'], $names);
    }
}
