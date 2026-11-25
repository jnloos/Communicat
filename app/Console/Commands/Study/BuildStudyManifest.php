<?php

namespace App\Console\Commands\Study;

use App\Services\Study\Planning\InvalidManifest;
use App\Services\Study\Planning\ManifestFolder;
use Illuminate\Console\Command;

/**
 * Thesis study, see docs/study.md.
 *
 * Writes <folder>/manifest.json from a study folder, for an upload elsewhere or a
 * look at the whole file, and lists the scenarios. study:plan and the plan page
 * read the folder directly, so this is never a step before planning.
 */
class BuildStudyManifest extends Command
{
    protected $signature = 'study:manifest
        {folder : A folder under resources/study/manifests (by name) or a path}';

    protected $description = 'Build manifest.json from a study folder and list its scenarios.';

    public function handle(): int
    {
        $folder = $this->argument('folder');
        $folder = is_dir($folder) ? rtrim($folder, '/') : ManifestFolder::path($folder);

        try {
            $json = ManifestFolder::assemble($folder);
        } catch (InvalidManifest $exception) {
            foreach ($exception->problems as $problem) {
                $this->error($problem);
            }

            return self::FAILURE;
        }

        file_put_contents("{$folder}/manifest.json", $json."\n");
        $data = json_decode($json, true);

        $this->info("Wrote {$folder}/manifest.json");
        $this->line(sprintf('Study %s: %s, %s, %d turns, seating %s, master seed %d',
            $data['study'] ?? '?', $data['model'] ?? '?', implode(', ', $data['pipelines'] ?? []), $data['turn_budget'] ?? 0, $data['seating'] ?? 'drawn', $data['master_seed'] ?? 0));
        $this->table(['', 'High / low', 'Topic'], array_map(
            fn (array $scenario) => [sprintf('S%02d', $scenario['id']), ($scenario['titles']['high'] ?? '?').' / '.($scenario['titles']['low'] ?? '?'), $scenario['topic'] ?? '?'],
            $data['scenarios'],
        ));

        return self::SUCCESS;
    }
}
