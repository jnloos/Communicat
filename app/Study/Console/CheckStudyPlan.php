<?php

namespace App\Study\Console;

use App\Study\Planning\PlanCheck;
use Illuminate\Console\Command;

/**
 * Study-only, removable: see app/Study/README.md.
 *
 * Recomputes every wave of a study from its stored manifest copies and lists
 * where the database differs from the plan. Fails on the first deviation, so it
 * can gate a batch.
 */
class CheckStudyPlan extends Command
{
    protected $signature = 'study:check {--study=main : The study named in the manifest}';

    protected $description = 'Compare the planned study runs with the projects in the database.';

    public function handle(PlanCheck $check): int
    {
        $study = (string) $this->option('study');
        $report = $check->run($study);

        if ($report['waves'] === 0) {
            $this->error("No wave of study {$study} is planned.");

            return self::FAILURE;
        }

        $this->line("{$report['waves']} wave(s), {$report['runs']} planned runs.");

        if ($report['commits'] !== []) {
            $this->table(['Commit', 'Started runs', 'Dirty'], $report['commits']);
        }

        if ($report['deviations'] === []) {
            $this->info('No deviations.');

            return self::SUCCESS;
        }

        $this->table(['Run', 'What', 'Planned', 'Found'], $report['deviations']);
        $this->error(count($report['deviations']).' deviation(s).');

        return self::FAILURE;
    }
}
