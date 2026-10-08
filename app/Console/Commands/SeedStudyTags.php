<?php

namespace App\Console\Commands;

use App\Services\Tags\StudyTagSeeder;
use Illuminate\Console\Command;

/**
 * One-off: tags the study cells that ran before tags existed. A dry run by
 * default — the plan has to be read before anything is written.
 */
class SeedStudyTags extends Command
{
    protected $signature = 'tags:seed-study {--apply : Write the planned tags; without it the command only shows them}';

    protected $description = 'Tag the existing study cells: condition from the title, the seat-1 persona of each status cell as title holder.';

    public function handle(StudyTagSeeder $seeder): int
    {
        $plan = $seeder->plan();

        $this->table(
            ['Project', 'Title', StudyTagSeeder::CONDITION_CATEGORY],
            array_map(fn (array $row) => [$row['project']->id, str($row['project']->title)->limit(50), $row['condition']], $plan->projects),
        );

        $this->table(
            ['Expert', 'Name', StudyTagSeeder::TITLE_CATEGORY, 'Status cells'],
            array_map(fn (array $row) => [$row['expert']->id, $row['expert']->name, StudyTagSeeder::TITLE_YES, implode(', ', $row['projects'])], $plan->titleHolders),
        );

        foreach ($plan->conflicts as $conflict) {
            $this->warn(sprintf(
                'Conflict: %s (E%d) holds seat 1 in status cells %s but also sits in no-status cells %s — not tagged.',
                $conflict['expert']->name, $conflict['expert']->id, implode(', ', $conflict['status']), implode(', ', $conflict['control']),
            ));
        }

        foreach ($plan->withoutSeatOne as $project) {
            $this->warn("Status cell {$project->id} has nobody on seat 1 — no title holder.");
        }

        $this->line("{$plan->unmatched} project(s) name no condition in their title and stay untouched.");

        if (! $this->option('apply')) {
            $this->info('Dry run: nothing written. Run again with --apply to write these tags.');

            return self::SUCCESS;
        }

        $seeder->apply($plan);
        $this->info('Tags written.');

        return self::SUCCESS;
    }
}
