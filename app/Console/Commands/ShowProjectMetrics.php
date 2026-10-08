<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesProject;
use App\Discussion\Metrics\ShareTrajectory;
use App\Discussion\Metrics\SpeakingShares;
use App\Discussion\Values\Participant;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\ShareSeries;
use App\Discussion\Values\SpeakerShare;
use App\Discussion\Values\Trajectory;
use App\Discussion\Values\TrajectoryPoint;
use App\Models\Project;
use Illuminate\Console\Command;

/**
 * A run's speaking shares on stdout, for a human or for a script.
 *
 * Reads SpeakingShares, the one place the study's counting rules live, so a
 * number here is the same number the panel shows and the thesis's evaluation
 * will report. `--format=csv` and `--format=json` write nothing but the data,
 * so they can be piped.
 *
 * `--trajectory` prints the shares after every spoken turn instead of the
 * totals (ShareTrajectory): cumulative and over the last `--window` turns. It
 * is data for a script, so it comes as csv or json only.
 */
class ShowProjectMetrics extends Command
{
    use ResolvesProject;

    protected $signature = 'project:metrics
        {project : The project id}
        {--format=table : table, json or csv}
        {--trajectory : The shares after every turn instead of the totals (csv or json only)}
        {--window= : Turns a rolling share spans with --trajectory; defaults to config discussion.share_window}';

    protected $description = "Print a run's turn share and conversation share.";

    public function handle(SpeakingShares $shares, ShareTrajectory $trajectories): int
    {
        $project = $this->project();

        if ($project === null) {
            return self::FAILURE;
        }

        $format = $this->option('format');

        if (! in_array($format, ['table', 'json', 'csv'], true)) {
            $this->error("Unknown format [{$format}]. Use table, json or csv.");

            return self::FAILURE;
        }

        if ($this->option('trajectory')) {
            return $this->trajectory($project, $trajectories, $format);
        }

        $report = $shares->forProject($project);

        match ($format) {
            'json' => $this->renderJson($project, $report),
            'csv' => $this->renderCsv($report),
            default => $this->renderTable($project, $report),
        };

        return self::SUCCESS;
    }

    private function renderTable(Project $project, ShareReport $report): void
    {
        $this->line("<info>{$project->title}</info> · {$project->pipeline} · {$project->model} · seed {$project->seed}");
        $this->newLine();

        if ($report->isEmpty()) {
            $this->warn('No turn has spoken yet.');

            return;
        }

        $this->table(
            ['Participant', 'Turns', 'Turn share', 'Words', 'Word share', 'Words/turn'],
            array_map(fn (SpeakerShare $s) => [
                $s->name,
                $s->turns,
                $this->percent($s->turnShare),
                $s->words,
                $this->percent($s->wordShare),
                number_format($s->wordsPerTurn(), 1),
            ], $report->speakers),
        );

        $this->table(['', ''], [
            ['Turns spoken', $report->spokenTurns],
            ['Turns failed without speaking', $report->failedTurns],
            ['Words total', $report->totalWords],
            ['Gini (turns)', number_format($report->turnGini, 3).' of '.number_format($report->giniCeiling, 3)],
            ['Gini (words)', number_format($report->wordGini, 3).' of '.number_format($report->giniCeiling, 3)],
        ]);
    }

    private function renderJson(Project $project, ShareReport $report): void
    {
        $this->output->writeln(json_encode([
            'project' => [
                'id' => $project->id,
                'title' => $project->title,
                'pipeline' => $project->pipeline,
                'model' => $project->model,
                'seed' => $project->seed,
                'turn_budget' => $project->turn_budget,
            ],
            'run' => [
                'turns_spoken' => $report->spokenTurns,
                'turns_failed_without_speaking' => $report->failedTurns,
                'words_total' => $report->totalWords,
                'gini_turns' => round($report->turnGini, 6),
                'gini_words' => round($report->wordGini, 6),
                'gini_ceiling' => round($report->giniCeiling, 6),
            ],
            'speakers' => array_map(fn (SpeakerShare $s) => [
                'expert_id' => $s->expertId,
                'name' => $s->name,
                'seat' => $s->seat,
                'turns' => $s->turns,
                'turn_share' => round($s->turnShare, 6),
                'words' => $s->words,
                'word_share' => round($s->wordShare, 6),
                'words_per_turn' => round($s->wordsPerTurn(), 6),
            ], $report->speakers),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** One row per speaker, so several runs concatenate into one table. */
    private function renderCsv(ShareReport $report): void
    {
        $this->writeCsv(
            ['expert_id', 'name', 'seat', 'turns', 'turn_share', 'words', 'word_share', 'words_per_turn'],
            array_map(fn (SpeakerShare $share) => [
                $share->expertId,
                $share->name,
                $share->seat,
                $share->turns,
                round($share->turnShare, 6),
                $share->words,
                round($share->wordShare, 6),
                round($share->wordsPerTurn(), 6),
            ], $report->speakers),
        );
    }

    private function trajectory(Project $project, ShareTrajectory $trajectories, string $format): int
    {
        if ($format === 'table') {
            $this->error('A trajectory is data for a script: use --format=csv or --format=json.');

            return self::FAILURE;
        }

        $window = $this->option('window');

        if ($window !== null) {
            $window = filter_var($window, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if ($window === false) {
                $this->error("--window must be a whole number of turns, at least 1; got [{$this->option('window')}].");

                return self::FAILURE;
            }
        }

        $trajectory = $trajectories->forProject($project, $window);

        $format === 'json'
            ? $this->renderTrajectoryJson($project, $trajectory)
            : $this->renderTrajectoryCsv($project, $trajectory);

        return self::SUCCESS;
    }

    private function renderTrajectoryJson(Project $project, Trajectory $trajectory): void
    {
        $this->output->writeln(json_encode([
            'project' => ['id' => $project->id, 'title' => $project->title, 'pipeline' => $project->pipeline],
            'window' => $trajectory->window,
            'participants' => array_map(fn (Participant $p) => [
                'expert_id' => $p->expertId,
                'name' => $p->name,
                'seat' => $p->seat,
            ], $trajectory->participants),
            'points' => array_map(fn (TrajectoryPoint $point) => [
                'turn' => $point->turn,
                'speaker_id' => $point->speakerId,
                'shares' => array_map(
                    fn (array $byExpert) => array_map(fn (float $share) => round($share, 6), $byExpert),
                    $point->shares,
                ),
            ], $trajectory->points),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Long format, one row per turn and participant, the shape a plotting or
     * mixed-model script reads directly. Unlike the totals it carries the
     * project id, because the trajectories of several runs are meant to be
     * concatenated into one file.
     */
    private function renderTrajectoryCsv(Project $project, Trajectory $trajectory): void
    {
        $series = ShareSeries::cases();
        $rows = [];

        foreach ($trajectory->points as $point) {
            foreach ($trajectory->participants as $participant) {
                $rows[] = [
                    $project->id,
                    $point->turn,
                    $point->speakerId,
                    $participant->expertId,
                    $participant->name,
                    $participant->seat,
                    $trajectory->window,
                    ...array_map(fn (ShareSeries $s) => round($point->share($s, $participant->expertId), 6), $series),
                ];
            }
        }

        $this->writeCsv(
            ['project_id', 'turn', 'speaker_id', 'expert_id', 'name', 'seat', 'window',
                ...array_map(fn (ShareSeries $s) => $s->value, $series)],
            $rows,
        );
    }

    /**
     * Through the command's own output rather than php://output, so the data
     * goes wherever the console output goes — including Artisan::output().
     *
     * @param  list<string>  $header
     * @param  list<list<scalar|null>>  $rows
     */
    private function writeCsv(array $header, array $rows): void
    {
        $buffer = fopen('php://temp', 'r+');

        foreach ([$header, ...$rows] as $row) {
            fputcsv($buffer, $row);
        }

        rewind($buffer);
        $this->output->write(stream_get_contents($buffer));
        fclose($buffer);
    }

    private function percent(float $fraction): string
    {
        return number_format($fraction * 100, 1).' %';
    }
}
