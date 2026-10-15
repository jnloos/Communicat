<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesActingUser;
use App\Models\Expert;
use App\Models\Project;
use App\Services\Transfer\BundleExporter;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

class ExportBundle extends Command
{
    use ResolvesActingUser;

    protected $signature = 'bundle:export
        {path : Where to write the .zip}
        {--project=* : Export this one project}
        {--expert=* : Export this one expert}
        {--user= : Whose groups and questions go into a global export; default the first admin}';

    protected $description = 'Export a project, an expert or everything as a bundle.';

    public function handle(BundleExporter $exporter): int
    {
        $path = (string) $this->argument('path');
        $projectIds = $this->option('project');
        $expertIds = $this->option('expert');

        $requested = count($projectIds) + count($expertIds);

        if ($requested > 1) {
            $this->error('Give exactly one --project, exactly one --expert, or neither for everything.');

            return self::FAILURE;
        }

        foreach ($projectIds as $id) {
            if (! Project::whereKey($id)->exists()) {
                $this->error("No project with id {$id}.");

                return self::FAILURE;
            }
        }

        foreach ($expertIds as $id) {
            if (! Expert::whereKey($id)->exists()) {
                $this->error("No expert with id {$id}.");

                return self::FAILURE;
            }
        }

        if ($requested === 0) {
            $user = $this->actingUser();
            if ($user === null) {
                return self::FAILURE;
            }
            $exporter->everything($user, $path);
        } elseif ($projectIds !== []) {
            $exporter->project(Project::findOrFail($projectIds[0]), $path);
        } else {
            $exporter->expert(Expert::findOrFail($expertIds[0]), $path);
        }

        $this->info("Bundle written to {$path} (".Number::fileSize(filesize($path), precision: 1).').');

        return self::SUCCESS;
    }
}
