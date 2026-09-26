<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesProject;
use App\Discussion\Metrics\RunCosts;
use App\Discussion\TurnRunner;
use App\Models\JobLog;
use App\Models\Project;
use Illuminate\Console\Command;
use Throwable;

/**
 * Drive a discussion headlessly, one turn after another.
 *
 * It calls TurnRunner directly and touches neither GenerationLoop nor
 * ReadingPause: the loop is there to keep a browser in step with a run someone
 * is watching, and the pause is there so a human can read. Neither belongs in a
 * study run, where both would only cost wall-clock time.
 *
 * This is the smallest piece of the automation, not the matrix runner. It
 * drives one project; spreading the 24 cells of the design across projects
 * needs the status layer, which does not exist yet.
 */
class RunProject extends Command
{
    use ResolvesProject;

    protected $signature = 'project:run
        {project : The project id}
        {--turns=1 : How many turns to run}
        {--stop-on-failure : Stop at the first turn that fails instead of carrying on}';

    protected $description = 'Run a discussion headlessly for a number of turns.';

    public function handle(TurnRunner $runner, RunCosts $costs): int
    {
        $project = $this->project();

        if ($project === null) {
            return self::FAILURE;
        }

        $turns = (int) $this->option('turns');

        if ($turns < 1) {
            $this->error('--turns must be at least 1.');

            return self::FAILURE;
        }

        $this->line("<info>{$project->title}</info> · {$project->pipeline} · {$project->model} · seed {$project->seed}");

        $spokenBefore = $this->spokenTurns($project);
        // Taken before the first call so the cost below is this invocation's own,
        // not the project's running total -- project:cost reports that.
        $mark = $costs->mark();
        $bar = $this->output->createProgressBar($turns);
        $bar->start();

        $ran = 0;
        $failed = 0;
        $stoppedBecause = null;

        for ($i = 0; $i < $turns; $i++) {
            try {
                $result = $runner->run($project->fresh());
            } catch (Throwable $e) {
                // TurnRunner catches a stage's failure itself and logs it, so an
                // exception here is the runner or the database giving up. Carrying
                // on would produce the same failure every turn.
                $bar->finish();
                $this->newLine(2);
                $this->error('Aborted: '.class_basename($e).': '.$e->getMessage());

                return self::FAILURE;
            }

            $ran++;
            $bar->advance();

            if ($result->stop) {
                $stoppedBecause = $result->reason;

                if ($result->reason !== 'failed') {
                    break;
                }

                $failed++;

                if ($this->option('stop-on-failure')) {
                    break;
                }
            }
        }

        $bar->finish();
        $this->newLine(2);

        $spoken = $this->spokenTurns($project) - $spokenBefore;

        $this->table(['', ''], [
            ['Turns attempted', $ran],
            ['Turns that spoke', $spoken],
            ['Turns that failed', $failed],
            ['Budget', $project->turn_budget === null ? 'unlimited' : $this->spokenTurns($project).' / '.$project->turn_budget],
            ['Stopped because', $stoppedBecause ?? 'the requested turns were done'],
            ['Cost of this run', $this->cost($costs, $project, $mark)],
        ]);

        // A run that stops for a reason other than a failure did what it was
        // asked to; only a failure is worth a non-zero exit code.
        return $failed > 0 && $this->option('stop-on-failure') ? self::FAILURE : self::SUCCESS;
    }

    /** What these turns cost, as an estimate; see RunCosts for what it does and does not include. */
    private function cost(RunCosts $costs, Project $project, int $mark): string
    {
        $report = $costs->forProjectSince($project, $mark);

        if ($report->isEmpty()) {
            return 'no model call was made';
        }

        return '$'.number_format($report->usd(), 4)
            .($report->hasUnpricedModels() ? ' plus an unpriced model' : '')
            .' · '.number_format($report->tokensIn()).' in / '.number_format($report->tokensOut()).' out';
    }

    /** The study's counting rule: a turn spoke when it recorded words. */
    private function spokenTurns(Project $project): int
    {
        return JobLog::where('project_id', $project->id)->whereNotNull('words')->count();
    }
}
