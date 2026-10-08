<?php

namespace App\Discussion\Metrics;

use App\Discussion\Values\Participant;
use App\Discussion\Values\ShareSeries;
use App\Discussion\Values\Trajectory;
use App\Discussion\Values\TrajectoryPoint;
use App\Models\Expert;
use App\Models\JobLog;
use App\Models\Project;
use InvalidArgumentException;

/**
 * Each participant's turn share and word share after every spoken turn, both
 * cumulative and over the last few turns — the curve the sample plan reads to
 * decide how long a run must be before its shares have settled.
 *
 * It reads its turns through SpokenTurns, so the curve's last cumulative point
 * is exactly what SpeakingShares reports for the whole run.
 *
 * Why both views: a cumulative share flattens by arithmetic alone, since after
 * n turns one more turn moves it by at most about 1/n. A flat cumulative curve
 * therefore only says the average is pinned down. Whether the discussion itself
 * has stopped shifting shows in the rolling share, which forgets everything
 * older than the window.
 */
class ShareTrajectory
{
    public function __construct(private SpokenTurns $spoken) {}

    /**
     * @param  int|null  $window  spoken turns a rolling share spans; null reads
     *                            config('discussion.share_window')
     */
    public function forProject(Project $project, ?int $window = null, ?int $turnLimit = null): Trajectory
    {
        $window ??= (int) config('discussion.share_window');

        if ($window < 1) {
            throw new InvalidArgumentException("The rolling window must span at least one turn, got {$window}.");
        }

        $participants = $this->spoken->participants($project)
            ->map(fn (Expert $expert) => new Participant($expert->id, $expert->name, $expert->pivot?->seat))
            ->all();

        $turns = $this->spoken->firstTurns($project, $turnLimit)->get(['expert_id', 'words'])->all();

        return new Trajectory(
            participants: $participants,
            window: $window,
            points: $this->points($turns, $participants, $window),
        );
    }

    /**
     * @param  list<JobLog>  $turns
     * @param  list<Participant>  $participants
     * @return list<TrajectoryPoint>
     */
    private function points(array $turns, array $participants, int $window): array
    {
        $ids = array_map(fn (Participant $p) => $p->expertId, $participants);
        $allTurns = $allWords = $recentTurns = $recentWords = [];
        $points = [];

        foreach ($turns as $index => $turn) {
            $this->add($allTurns, $allWords, $turn, 1);
            $this->add($recentTurns, $recentWords, $turn, 1);

            // The turn that just slid out of the window.
            if ($index >= $window) {
                $this->add($recentTurns, $recentWords, $turns[$index - $window], -1);
            }

            $points[] = new TrajectoryPoint(
                turn: $index + 1,
                speakerId: (int) $turn->expert_id,
                shares: [
                    ShareSeries::CumulativeTurns->value => $this->shares($allTurns, $ids),
                    ShareSeries::CumulativeWords->value => $this->shares($allWords, $ids),
                    ShareSeries::RollingTurns->value => $this->shares($recentTurns, $ids),
                    ShareSeries::RollingWords->value => $this->shares($recentWords, $ids),
                ],
            );
        }

        return $points;
    }

    /**
     * Count one turn in (or, with -1, out of) a pair of tallies.
     *
     * @param  array<int, int>  $turns
     * @param  array<int, int>  $words
     */
    private function add(array &$turns, array &$words, JobLog $turn, int $sign): void
    {
        $speaker = (int) $turn->expert_id;

        $turns[$speaker] = ($turns[$speaker] ?? 0) + $sign;
        $words[$speaker] = ($words[$speaker] ?? 0) + $sign * (int) $turn->words;
    }

    /**
     * Each participant's share of a tally. The total runs over everyone who
     * spoke, seated or not, exactly as SpeakingShares divides — so an expert
     * unseated mid-run still takes up their part of the denominator.
     *
     * @param  array<int, int>  $tally  amount per expert id
     * @param  list<int>  $ids
     * @return array<int, float>
     */
    private function shares(array $tally, array $ids): array
    {
        $total = array_sum($tally);
        $shares = [];

        foreach ($ids as $id) {
            $shares[$id] = $total === 0 ? 0.0 : ($tally[$id] ?? 0) / $total;
        }

        return $shares;
    }
}
