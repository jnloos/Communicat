<?php

namespace Tests\Unit\Discussion;

use App\Discussion\Metrics\ShareTrajectory;
use App\Discussion\Metrics\SpeakingShares;
use App\Discussion\Values\ShareSeries;
use App\Discussion\Values\Trajectory;
use App\Discussion\Values\TurnRange;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The share trajectory on synthetic extreme cases, where the right answer can
 * be worked out by hand. The evaluation reads these curves to decide how long a
 * run has to be, so a wrong curve would mean a wrong sample plan.
 */
class ShareTrajectoryTest extends TestCase
{
    use RefreshDatabase;

    private Project $project;

    /** @var array<string, Expert> */
    private array $experts = [];

    private int $turnIndex = 0;

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
    private function turn(string $name, ?int $words, string $status = 'success'): void
    {
        JobLog::create([
            'job_class' => 'App\Discussion\TurnRunner',
            'project_id' => $this->project->id,
            'status' => $status,
            'started_at' => now(),
            'turn_index' => ++$this->turnIndex,
            'expert_id' => $this->experts[$name]->id,
            'words' => $words,
        ]);
    }

    private function trajectory(int $window = 20): Trajectory
    {
        return app(ShareTrajectory::class)->forProject($this->project->fresh(), $window);
    }

    private function id(string $name): int
    {
        return $this->experts[$name]->id;
    }

    public function test_one_agent_who_always_speaks_holds_everything_from_the_first_turn(): void
    {
        foreach (range(1, 30) as $ignored) {
            $this->turn('Alice', 10);
        }

        $trajectory = $this->trajectory();

        $this->assertCount(30, $trajectory->points);

        foreach ($trajectory->points as $point) {
            foreach (ShareSeries::cases() as $series) {
                $this->assertSame(1.0, $point->share($series, $this->id('Alice')));
                $this->assertSame(0.0, $point->share($series, $this->id('Bob')));
            }
        }
    }

    public function test_strict_rotation_settles_at_a_quarter_after_every_full_round(): void
    {
        foreach (range(1, 10) as $ignored) {
            foreach (['Alice', 'Bob', 'Carol', 'Dave'] as $name) {
                $this->turn($name, 10);
            }
        }

        $points = $this->trajectory(window: 8)->points;

        // After turn 1 only Alice has spoken; after every full round all four are equal.
        $this->assertSame(1.0, $points[0]->share(ShareSeries::CumulativeTurns, $this->id('Alice')));
        $this->assertSame(0.5, $points[1]->share(ShareSeries::CumulativeTurns, $this->id('Alice')));

        foreach ([4, 8, 20, 40] as $turn) {
            foreach (ShareSeries::cases() as $series) {
                $this->assertSame(0.25, $points[$turn - 1]->share($series, $this->id('Dave')), "{$series->value} at turn {$turn}");
            }
        }
    }

    /**
     * The case the rolling share exists for: a late entrant. The cumulative
     * share carries the 29 silent turns forever, the rolling one forgets them
     * as soon as the window has passed.
     */
    public function test_an_agent_who_only_speaks_from_turn_30_shows_in_the_rolling_share_first(): void
    {
        foreach (range(1, 29) as $ignored) {
            $this->turn('Alice', 10);
        }
        foreach (range(30, 49) as $ignored) {
            $this->turn('Bob', 10);
        }

        $points = $this->trajectory(window: 20)->points;

        $this->assertSame(0.0, $points[28]->share(ShareSeries::CumulativeTurns, $this->id('Bob')));
        $this->assertSame(0.0, $points[28]->share(ShareSeries::RollingTurns, $this->id('Bob')));

        // Turn 30: Bob's first, one of 30 overall and one of the last 20.
        $this->assertSame(round(1 / 30, 10), round($points[29]->share(ShareSeries::CumulativeTurns, $this->id('Bob')), 10));
        $this->assertSame(0.05, $points[29]->share(ShareSeries::RollingTurns, $this->id('Bob')));

        // Turn 49: the window holds Bob's 20 turns only, the cumulative share still 20 of 49.
        $this->assertSame(1.0, $points[48]->share(ShareSeries::RollingTurns, $this->id('Bob')));
        $this->assertSame(round(20 / 49, 10), round($points[48]->share(ShareSeries::CumulativeTurns, $this->id('Bob')), 10));
    }

    /** Same rule as SpeakingShares: a turn that spoke and then failed in Summarize counts. */
    public function test_a_failed_turn_that_spoke_counts(): void
    {
        $this->turn('Alice', 10);
        $this->turn('Bob', 30, status: 'failed');

        $trajectory = $this->trajectory();

        $this->assertCount(2, $trajectory->points);
        $this->assertSame($this->id('Bob'), $trajectory->points[1]->speakerId);
        $this->assertSame(0.5, $trajectory->points[1]->share(ShareSeries::CumulativeTurns, $this->id('Bob')));
        $this->assertSame(0.75, $trajectory->points[1]->share(ShareSeries::CumulativeWords, $this->id('Bob')));
    }

    /** A turn that never got to speak is not a point on the curve. */
    public function test_a_failed_turn_without_words_is_not_a_turn(): void
    {
        $this->turn('Alice', 10);
        $this->turn('Bob', null, status: 'failed');
        $this->turn('Carol', 10);

        $points = $this->trajectory()->points;

        $this->assertCount(2, $points);
        $this->assertSame(2, $points[1]->turn);
        $this->assertSame($this->id('Carol'), $points[1]->speakerId);
    }

    public function test_word_shares_are_weighted_by_length(): void
    {
        $this->turn('Alice', 10);
        $this->turn('Bob', 30);

        $last = $this->trajectory()->points[1];

        $this->assertSame(0.5, $last->share(ShareSeries::CumulativeTurns, $this->id('Alice')));
        $this->assertSame(0.25, $last->share(ShareSeries::CumulativeWords, $this->id('Alice')));
        $this->assertSame(0.75, $last->share(ShareSeries::RollingWords, $this->id('Bob')));
    }

    public function test_every_seated_expert_has_a_value_at_every_turn(): void
    {
        $this->turn('Alice', 10);

        $trajectory = $this->trajectory();

        $this->assertSame(['Alice', 'Bob', 'Carol', 'Dave'], array_map(fn ($p) => $p->name, $trajectory->participants));
        $this->assertSame(0.0, $trajectory->points[0]->share(ShareSeries::RollingWords, $this->id('Dave')));
    }

    /** Turns are ordered by turn_index, not by when the row happened to be written. */
    public function test_the_points_follow_the_turn_order(): void
    {
        foreach ([[2, 'Bob'], [1, 'Alice']] as [$index, $name]) {
            JobLog::create([
                'job_class' => 'App\Discussion\TurnRunner', 'project_id' => $this->project->id,
                'status' => 'success', 'started_at' => now(), 'turn_index' => $index,
                'expert_id' => $this->id($name), 'words' => 10,
            ]);
        }

        $points = $this->trajectory()->points;

        $this->assertSame($this->id('Alice'), $points[0]->speakerId);
        $this->assertSame($this->id('Bob'), $points[1]->speakerId);
    }

    /** Both read SpokenTurns, so the curve must end exactly where the totals stand. */
    public function test_the_last_cumulative_point_matches_speaking_shares(): void
    {
        $this->turn('Alice', 12);
        $this->turn('Bob', 40, status: 'failed');
        $this->turn('Carol', null, status: 'failed');
        $this->turn('Alice', 7);

        $last = $this->trajectory()->points[2];

        foreach (app(SpeakingShares::class)->forProject($this->project->fresh())->speakers as $share) {
            $this->assertSame($share->turnShare, $last->share(ShareSeries::CumulativeTurns, $share->expertId));
            $this->assertSame($share->wordShare, $last->share(ShareSeries::CumulativeWords, $share->expertId));
        }
    }

    public function test_a_run_without_turns_has_no_points_but_all_participants(): void
    {
        $trajectory = $this->trajectory();

        $this->assertTrue($trajectory->isEmpty());
        $this->assertCount(4, $trajectory->participants);
    }

    public function test_the_window_defaults_to_the_configured_value(): void
    {
        config(['discussion.share_window' => 7]);

        $this->assertSame(7, app(ShareTrajectory::class)->forProject($this->project->fresh())->window);
    }

    public function test_a_window_below_one_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->trajectory(window: 0);
    }

    public function test_a_window_cuts_the_curve_where_the_totals_stop(): void
    {
        $this->turn('Alice', 10);
        $this->turn('Alice', 10);
        $this->turn('Bob', 10);
        $this->turn('Bob', 10);

        $trajectory = app(ShareTrajectory::class)->forProject($this->project->fresh(), turns: new TurnRange(1, 2));
        $report = app(SpeakingShares::class)->forProject($this->project->fresh(), new TurnRange(1, 2));

        $this->assertCount(2, $trajectory->points);
        $this->assertSame(1.0, $trajectory->points[1]->share(ShareSeries::CumulativeTurns, $this->id('Alice')));
        $this->assertSame($report->speakers[0]->turnShare, $trajectory->points[1]->share(ShareSeries::CumulativeTurns, $this->id('Alice')));
    }

    public function test_a_window_starts_at_its_own_turn_with_the_real_turn_numbers(): void
    {
        $this->turn('Alice', 10);
        $this->turn('Alice', 10);
        $this->turn('Bob', 10);
        $this->turn('Bob', 10);

        $trajectory = app(ShareTrajectory::class)->forProject($this->project->fresh(), turns: new TurnRange(3, 4));

        $this->assertSame([3, 4], array_map(fn ($point) => $point->turn, $trajectory->points));
        $this->assertSame(1.0, $trajectory->points[0]->share(ShareSeries::CumulativeTurns, $this->id('Bob')));
        $this->assertSame(0.0, $trajectory->points[1]->share(ShareSeries::CumulativeTurns, $this->id('Alice')));
    }

    public function test_a_window_beyond_the_run_has_no_points(): void
    {
        $this->turn('Alice', 10);

        $this->assertSame([], app(ShareTrajectory::class)->forProject($this->project->fresh(), turns: new TurnRange(5, 8))->points);
    }
}
