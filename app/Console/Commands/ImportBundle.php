<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Transfer\BundleImporter;
use App\Services\Transfer\InvalidBundle;
use Illuminate\Console\Command;

class ImportBundle extends Command
{
    protected $signature = 'bundle:import
        {path : The .zip to read}
        {--user= : Who becomes owner; default the first admin}
        {--dry-run : Report what would happen and write nothing}';

    protected $description = 'Import a bundle: new projects and experts are added, existing ones skipped.';

    public function handle(BundleImporter $importer): int
    {
        $user = $this->option('user') ? User::findOrFail($this->option('user')) : User::where('is_admin', true)->orderBy('id')->firstOrFail();

        try {
            $report = $this->option('dry-run')
                ? $importer->preview((string) $this->argument('path'), $user)
                : $importer->import((string) $this->argument('path'), $user);
        } catch (InvalidBundle $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->table(['Section', 'Name', 'Outcome', 'Note'], collect($report->sections())
            ->flatMap(fn (string $section) => collect($report->entries($section))->map(fn (array $entry) => [$section, $entry['name'], $entry['outcome'], $entry['note'] ?? '']))
            ->all());

        return $report->hasFailures() ? self::FAILURE : self::SUCCESS;
    }
}
