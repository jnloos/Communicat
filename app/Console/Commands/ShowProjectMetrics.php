<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesProject;
use App\Discussion\Metrics\SpeakingShares;
use App\Discussion\Values\ShareReport;
use App\Discussion\Values\SpeakerShare;
use App\Models\Project;
use Illuminate\Console\Command;

/**
 * A run's speaking shares on stdout, for a human or for a script.
 *
 * Reads SpeakingShares, the one place the study's counting rules live, so a
 * number here is the same number the panel shows and the thesis's evaluation
 * will report. `--format=csv` and `--format=json` write nothing but the data,
 * so they can be piped.
 */
class ShowProjectMetrics extends Command
{
    use ResolvesProject;

    protected $signature = 'project:metrics
        {project : The project id}
        {--format=table : table, json or csv}';

    protected $description = "Print a run's turn share and conversation share.";

    public function handle(SpeakingShares $shares): int
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
        $out = fopen('php://output', 'w');

        fputcsv($out, ['expert_id', 'name', 'seat', 'turns', 'turn_share', 'words', 'word_share', 'words_per_turn']);

        foreach ($report->speakers as $share) {
            fputcsv($out, [
                $share->expertId,
                $share->name,
                $share->seat,
                $share->turns,
                round($share->turnShare, 6),
                $share->words,
                round($share->wordShare, 6),
                round($share->wordsPerTurn(), 6),
            ]);
        }

        fclose($out);
    }

    private function percent(float $fraction): string
    {
        return number_format($fraction * 100, 1).' %';
    }
}
