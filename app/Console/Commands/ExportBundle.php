<?php

namespace App\Console\Commands;

use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use App\Services\Transfer\BundleExporter;
use Illuminate\Console\Command;

class ExportBundle extends Command
{
    protected $signature = 'bundle:export
        {path : Where to write the .zip}
        {--project=* : Export only these projects}
        {--expert=* : Export only these experts}
        {--user= : Whose groups and questions go into a global export; default the first admin}';

    protected $description = 'Export a project, an expert or everything as a bundle.';

    public function handle(BundleExporter $exporter): int
    {
        $path = (string) $this->argument('path');
        $projects = Project::whereIn('id', $this->option('project'))->get();
        $experts = Expert::whereIn('id', $this->option('expert'))->get();

        if ($projects->count() === 1 && $experts->isEmpty()) {
            $exporter->project($projects->sole(), $path);
        } elseif ($experts->count() === 1 && $projects->isEmpty()) {
            $exporter->expert($experts->sole(), $path);
        } elseif ($projects->isEmpty() && $experts->isEmpty() && $this->option('project') === [] && $this->option('expert') === []) {
            $user = $this->option('user') ? User::findOrFail($this->option('user')) : User::where('is_admin', true)->orderBy('id')->firstOrFail();
            $exporter->everything($user, $path);
        } else {
            $this->error('Give exactly one --project, exactly one --expert, or neither for everything.');

            return self::FAILURE;
        }

        $this->info("Bundle written to {$path} (".number_format(filesize($path) / 1024 / 1024, 1).' MB).');

        return self::SUCCESS;
    }
}
