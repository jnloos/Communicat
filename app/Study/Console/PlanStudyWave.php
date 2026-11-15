<?php

namespace App\Study\Console;

use App\Console\Commands\Concerns\ResolvesActingUser;
use App\Study\Planning\PlanPreview;
use App\Study\Planning\WavePlanning;
use Illuminate\Console\Command;

/**
 * Study-only, removable: see app/Study/README.md.
 *
 * Plans a wave from a manifest: --dry-run shows what it would write, including
 * one real prompt per scenario; --apply writes it, scenario by scenario, and can
 * be repeated after an abort. Same checks and numbers as the planning page.
 */
class PlanStudyWave extends Command
{
    use ResolvesActingUser;

    protected $signature = 'study:plan
        {manifest : Path to the study manifest (JSON)}
        {--wave= : Wave number, from 1}
        {--scenarios= : Scenario range FROM-TO}
        {--user= : Owner of the projects; default the first admin}
        {--dry-run : Show what would be written, write nothing}
        {--apply : Write the wave}';

    protected $description = 'Plan a study wave from its manifest: preview it or write it.';

    public function handle(WavePlanning $planning): int
    {
        if ($this->option('dry-run') === $this->option('apply')) {
            $this->error('Pass exactly one of --dry-run or --apply.');

            return self::FAILURE;
        }

        $request = $this->request();
        $owner = $request === null ? null : $this->actingUser();
        if ($owner === null) {
            return self::FAILURE;
        }

        $wave = $planning->prepare($request['json'], $request['wave'], $request['from'], $request['to']);
        $this->overview($planning->preview($wave, $owner, withPrompts: (bool) $this->option('dry-run')));

        if (! $wave->isAcceptable()) {
            return self::FAILURE;
        }

        if ($this->option('apply')) {
            $planning->apply($planning->record($wave, $owner), fn (int $scenario) => $this->line("Scenario {$scenario} written."));
            $this->info("Wave {$wave->number} applied.");
        }

        return self::SUCCESS;
    }

    /** @return array{json: string, wave: int, from: int, to: int}|null */
    private function request(): ?array
    {
        $path = $this->argument('manifest');
        if (! is_file($path)) {
            $this->error("No manifest at {$path}.");

            return null;
        }

        if (! ctype_digit((string) $this->option('wave')) || preg_match('/^(\d+)-(\d+)$/', (string) $this->option('scenarios'), $range) !== 1) {
            $this->error('Pass --wave=N and --scenarios=FROM-TO.');

            return null;
        }

        return ['json' => file_get_contents($path), 'wave' => (int) $this->option('wave'), 'from' => (int) $range[1], 'to' => (int) $range[2]];
    }

    private function overview(array $preview): void
    {
        foreach ($preview['problems'] as $problem) {
            $this->error($problem);
        }

        foreach (PlanPreview::tables($preview) as $table) {
            $this->info($table['title']);
            $this->table($table['columns'], $table['rows']);
        }

        if ($preview['runs']['total'] > 0) {
            $this->line(__('study::plan.duplicates', ['count' => $preview['duplicate_full_names']]));
        }

        foreach ($preview['prompts'] as $scenario => $prompt) {
            $this->newLine();
            $this->info(__('study::plan.prompt', ['scenario' => $scenario]));
            $this->line($prompt);
        }
    }
}
