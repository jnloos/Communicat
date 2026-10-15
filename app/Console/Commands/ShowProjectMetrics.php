<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesProject;
use App\Console\MetricsFormat;
use App\Discussion\Metrics\ShareTrajectory;
use App\Discussion\Metrics\SpeakingShares;
use App\Discussion\Support\LocaleNumber;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\SpeakerShare;
use App\Discussion\Values\Trajectory;
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

        $format = MetricsFormat::tryFrom((string) $this->option('format'));

        if ($format === null) {
            $this->error("Unknown format [{$this->option('format')}]. Use ".MetricsFormat::listed().'.');

            return self::FAILURE;
        }

        if ($this->option('trajectory')) {
            return $this->trajectory($project, $trajectories, $format);
        }

        $report = $shares->forProject($project);

        match ($format) {
            MetricsFormat::Json => $this->writeJson(['project' => $this->projectFields($project), ...$report->toArray()]),
            MetricsFormat::Csv => $this->writeCsv(SpeakerShare::COLUMNS, $report->toArray()['speakers']),
            MetricsFormat::Table => $this->renderTable($project, $report),
        };

        return self::SUCCESS;
    }

    private function trajectory(Project $project, ShareTrajectory $trajectories, MetricsFormat $format): int
    {
        if ($format === MetricsFormat::Table) {
            $this->error('A trajectory is data for a script: use --format=csv or --format=json.');

            return self::FAILURE;
        }

        if (! $this->windowIsValid()) {
            $this->error("--window must be a whole number of turns, at least 1; got [{$this->option('window')}].");

            return self::FAILURE;
        }

        $trajectory = $trajectories->forProject($project, $this->window());

        $format === MetricsFormat::Json
            ? $this->writeJson([
                'project' => ['id' => $project->id, 'title' => $project->title, 'pipeline' => $project->pipeline],
                ...$trajectory->toArray(),
            ])
            : $this->writeTrajectoryCsv($project, $trajectory);

        return self::SUCCESS;
    }

    /** Left out, the window falls back to the config; given, it must be a whole number from 1. */
    private function windowIsValid(): bool
    {
        $window = $this->option('window');

        return $window === null
            || filter_var($window, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }

    /** Null leaves the window to ShareTrajectory's default; call windowIsValid() first. */
    private function window(): ?int
    {
        $window = $this->option('window');

        return $window === null ? null : (int) $window;
    }

    /** @return array<string, int|string|null> */
    private function projectFields(Project $project): array
    {
        return [
            'id' => $project->id,
            'title' => $project->title,
            'pipeline' => $project->pipeline,
            'model' => $project->model,
            'seed' => $project->seed,
            'turn_budget' => $project->turn_budget,
        ];
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
                LocaleNumber::percent($s->turnShare),
                $s->words,
                LocaleNumber::percent($s->wordShare),
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

    /**
     * One row per turn and participant. Unlike the totals it carries the
     * project id, because the trajectories of several runs are meant to be
     * concatenated into one file.
     */
    private function writeTrajectoryCsv(Project $project, Trajectory $trajectory): void
    {
        $this->writeCsv(
            ['project_id', ...Trajectory::longColumns()],
            array_map(fn (array $row) => ['project_id' => $project->id, ...$row], $trajectory->longRows()),
        );
    }

    /** @param  array<string, mixed>  $data */
    private function writeJson(array $data): void
    {
        $this->output->writeln(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Through the command's own output rather than php://output, so the data
     * goes wherever the console output goes — including Artisan::output().
     *
     * @param  list<string>  $header
     * @param  list<array<array-key, scalar|null>>  $rows
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
}
