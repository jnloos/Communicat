<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesProject;
use App\Discussion\Metrics\RunCosts;
use App\Discussion\Values\CostLine;
use App\Discussion\Values\CostReport;
use App\Models\JobLog;
use App\Models\Project;
use Illuminate\Console\Command;

/**
 * What a run has cost so far, and what one more turn would cost.
 *
 * Bookkeeping beside the study's measures, not one of them: the thesis counts
 * words, never tokens. This is here so the next run can be budgeted from the
 * last one instead of from a guess -- the input side grows with the history,
 * so the price per turn rises over a run and a linear guess from turn one is
 * always too low.
 */
class ShowProjectCost extends Command
{
    use ResolvesProject;

    protected $signature = 'project:cost
        {project : The project id}
        {--format=table : table or json}';

    protected $description = 'Print what a run has cost, per model and in total.';

    public function handle(RunCosts $costs): int
    {
        $project = $this->project();

        if ($project === null) {
            return self::FAILURE;
        }

        $format = $this->option('format');

        if (! in_array($format, ['table', 'json'], true)) {
            $this->error("Unknown format [{$format}]. Use table or json.");

            return self::FAILURE;
        }

        $report = $costs->forProject($project);

        $format === 'json'
            ? $this->renderJson($project, $report)
            : $this->renderTable($project, $report);

        return self::SUCCESS;
    }

    private function renderTable(Project $project, CostReport $report): void
    {
        $this->line("<info>{$project->title}</info> · {$project->pipeline} · {$project->model} · seed {$project->seed}");
        $this->newLine();

        if ($report->isEmpty()) {
            $this->warn('No model call has been made for this project yet.');

            return;
        }

        $this->table(
            ['Provider', 'Model', 'Calls', 'Tokens in', 'Tokens out', 'USD'],
            array_map(fn (CostLine $line) => [
                $line->provider,
                $line->model,
                number_format($line->calls),
                number_format($line->tokensIn),
                number_format($line->tokensOut),
                $line->usd === null ? 'unpriced' : $this->usd($line->usd),
            ], $report->lines),
        );

        $spoken = $this->spokenTurns($project);

        $this->table(['', ''], array_filter([
            ['Calls', number_format($report->calls())],
            ['Tokens in / out', number_format($report->tokensIn()).' / '.number_format($report->tokensOut())],
            ['Cost', $this->usd($report->usd())],
            $spoken === 0 ? null : ['Cost per spoken turn', $this->usd($report->usd() / $spoken)],
            $spoken === 0 ? null : ['Turns spoken', $spoken],
        ]));

        if ($report->hasUnpricedModels()) {
            $this->warn('At least one model has no price in config/ai.php — the total is short by whatever it cost.');
        }
    }

    private function renderJson(Project $project, CostReport $report): void
    {
        $this->output->writeln(json_encode([
            'project' => ['id' => $project->id, 'title' => $project->title, 'model' => $project->model],
            'run' => [
                'calls' => $report->calls(),
                'tokens_in' => $report->tokensIn(),
                'tokens_out' => $report->tokensOut(),
                'usd' => round($report->usd(), 6),
                'has_unpriced_models' => $report->hasUnpricedModels(),
                'turns_spoken' => $this->spokenTurns($project),
            ],
            'models' => array_map(fn (CostLine $line) => [
                'provider' => $line->provider,
                'model' => $line->model,
                'calls' => $line->calls,
                'tokens_in' => $line->tokensIn,
                'tokens_out' => $line->tokensOut,
                'usd' => $line->usd === null ? null : round($line->usd, 6),
            ], $report->lines),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** The study's counting rule, so cost per turn divides by the same turns the shares do. */
    private function spokenTurns(Project $project): int
    {
        return JobLog::where('project_id', $project->id)->whereNotNull('words')->count();
    }

    private function usd(float $amount): string
    {
        return '$'.number_format($amount, 4);
    }
}
