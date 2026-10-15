<?php

namespace App\Discussion\Metrics\Questions;

use App\Discussion\Metrics\ShareTrajectory;
use App\Discussion\Metrics\SpeakingShares;
use App\Discussion\Metrics\SpokenTurns;
use App\Discussion\Values\QuestionDefinition;
use App\Discussion\Values\QuestionResult;
use App\Discussion\Values\TurnRange;
use App\Discussion\Values\TurnWindow;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Answers a question over the runs a user may read, in three steps: RunPlacer
 * sorts each run into a panel and its agents into groups, RunReader reads each
 * placed run over the shared window, PanelCollector averages and lays out the
 * answer. Every number comes from SpeakingShares and ShareTrajectory, so the
 * counting rules stay in SpokenTurns.
 *
 * - A run without a spoken turn, without a value in a run dimension, or
 *   without anyone the people filter accepts, drops out and is counted.
 * - Every run is read over the same turns: the asked window, its upper bound
 *   capped at the shortest run that contributes to the answer. A run that drops
 *   out (no value in a dimension, nobody the people filter accepts) does not
 *   shorten the window of the others.
 * - A curve point is the mean over the runs that reached that turn. Every run
 *   counted has at least as many turns as the window's upper bound, so n is the
 *   same at each point, unless the window starts beyond the shortest run.
 *
 * Each run's panel and groups are worked out once, over the whole run; only
 * the report over the window is read a second time.
 */
final class QuestionEvaluator
{
    private readonly RunPlacer $placer;

    private readonly RunReader $reader;

    public function __construct(
        private readonly QuestionRuns $runs,
        private readonly DimensionValues $dimensions,
        SpeakingShares $shares,
        ShareTrajectory $trajectories,
        private readonly SpokenTurns $spoken,
    ) {
        // One SpeakingShares for both: its SpokenTurns remembers who left each run, so placing and reading a run ask once.
        $this->placer = new RunPlacer($shares, $spoken);
        $this->reader = new RunReader($shares, $trajectories);
    }

    public function evaluate(User $user, QuestionDefinition $question): QuestionResult
    {
        $projects = $this->runs->for($user, $question->projects);
        // Groups belong to the viewer, and the tags of all runs are read ahead, so this copy serves this answer only.
        $values = $this->dimensions->forUser($user)->withRuns($projects);
        $places = $this->placer->place($question, $projects, $values);
        $window = $this->window($question->turns, $projects->filter(fn (Project $project) => $places[$project->id] !== null));
        $panels = new PanelCollector;

        foreach ($projects as $project) {
            $place = $window->startsBeyondShortestRun() ? null : $places[$project->id];
            $read = $place === null ? [] : $this->reader->read($question, $project, $place, $window->turns);

            if ($read === []) {
                $panels->exclude();

                continue;
            }

            $panels->add($place, $read);
        }

        return $panels->result($question, $values, $projects->count(), $window);
    }

    /**
     * The asked window over the runs that contribute, capped at the shortest
     * of them. A run that never spoke has no length to cap by.
     *
     * @param  Collection<int, Project>  $contributing
     */
    private function window(TurnRange $asked, Collection $contributing): TurnWindow
    {
        $lengths = array_filter($this->spoken->counts($contributing), fn (int $length) => $length > 0);

        return new TurnWindow($asked, $lengths === [] ? null : min($lengths));
    }
}
