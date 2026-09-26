<?php

namespace App\Console\Commands;

use App\Models\JobLog;
use App\Models\Project;
use Illuminate\Console\Command;

/**
 * Every discussion with the configuration that makes a run comparable, and how
 * far it has got.
 *
 * Progress counts spoken turns, not finished jobs: a turn that spoke and then
 * failed in a later stage produced a contribution and consumed budget, which is
 * the same rule TurnRunner and SpeakingShares apply.
 */
class ListProjects extends Command
{
    protected $signature = 'project:list {--frozen : Only projects whose run_config is already frozen}';

    protected $description = 'List the discussions with their pipeline, model, seed and progress.';

    public function handle(): int
    {
        $query = Project::query()->orderBy('id');

        if ($this->option('frozen')) {
            $query->whereNotNull('run_config');
        }

        $projects = $query->get();

        if ($projects->isEmpty()) {
            $this->warn($this->option('frozen') ? 'No project has started a run yet.' : 'No projects.');

            return self::SUCCESS;
        }

        $spoken = JobLog::whereNotNull('words')
            ->selectRaw('project_id, count(*) as turns')
            ->groupBy('project_id')
            ->pluck('turns', 'project_id');

        $this->table(
            ['Id', 'Title', 'Pipeline', 'Model', 'Seed', 'Seats', 'Spoken', 'Started'],
            $projects->map(fn (Project $project) => [
                $project->id,
                str($project->title)->limit(34),
                $project->pipeline,
                $project->model,
                $project->seed,
                $project->contributingExperts()->count(),
                ($spoken[$project->id] ?? 0).($project->turn_budget === null ? '' : ' / '.$project->turn_budget),
                $project->run_config === null ? 'no' : 'yes',
            ])->all(),
        );

        return self::SUCCESS;
    }
}
