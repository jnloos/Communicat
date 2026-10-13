<?php

namespace App\Console\Commands;

use App\Discussion\Metrics\Questions\QuestionRuns;
use App\Discussion\Values\ProjectFilter;
use App\Models\User;
use App\Services\StudyExport\StudyExport;
use Illuminate\Console\Command;

class ExportStudyTurns extends Command
{
    protected $signature = 'study:export-turns
        {path : Where to write the .zip with runs.csv, participants.csv and turns.csv}
        {--project=* : Only these projects}
        {--group=* : Only runs in these sidebar groups of --user}
        {--pipeline=* : Only runs of these pipelines}
        {--user= : Whose access and groups count; default the first admin}';

    protected $description = 'Export the raw per-turn data of the runs as three CSV files.';

    public function handle(QuestionRuns $runs, StudyExport $export): int
    {
        $user = $this->option('user') ? User::findOrFail($this->option('user')) : User::where('is_admin', true)->orderBy('id')->firstOrFail();
        $filter = ProjectFilter::fromArray(['groups' => $this->option('group'), 'pipelines' => $this->option('pipeline')]);
        $selected = $runs->for($user, $filter)
            ->when($this->option('project') !== [], fn ($all) => $all->whereIn('id', array_map('intval', $this->option('project'))))
            ->values();

        $export->write($selected, $user, (string) $this->argument('path'));
        $this->info("{$selected->count()} runs written to {$this->argument('path')}.");

        return self::SUCCESS;
    }
}
