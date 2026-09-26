<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesProject;
use App\Models\Expert;
use App\Models\Project;
use Illuminate\Console\Command;

/**
 * Seat or unseat experts on a discussion.
 *
 * Seats matter beyond bookkeeping: round robin rotates by seat, and the planned
 * status rotation moves the titled agent over seats rather than over array
 * positions. Removing a contributor leaves the other seats as they are, so the
 * numbering can have gaps — which is why this prints them.
 */
class SetProjectContributors extends Command
{
    use ResolvesProject;

    protected $signature = 'project:contributors
        {project : The project id}
        {--add= : Comma-separated expert ids to seat, in seat order}
        {--remove= : Comma-separated expert ids to unseat}';

    protected $description = 'Seat or unseat the experts of a discussion.';

    public function handle(): int
    {
        $project = $this->project();

        if ($project === null) {
            return self::FAILURE;
        }

        foreach ($this->ids('remove') as $id) {
            $expert = Expert::find($id);

            if ($expert === null) {
                $this->error("No expert with id {$id}.");

                return self::FAILURE;
            }

            $project->removeContributingExpert($expert);
            $this->line("Unseated {$expert->name}.");
        }

        foreach ($this->ids('add') as $id) {
            $expert = Expert::find($id);

            if ($expert === null) {
                $this->error("No expert with id {$id}.");

                return self::FAILURE;
            }

            if (! $project->fresh()->canAddExpert()) {
                $this->error('At most '.Project::MAX_CONTRIBUTING_EXPERTS.' experts can be seated.');

                return self::FAILURE;
            }

            $project->addContributingExpert($expert);
            $this->line("Seated {$expert->name}.");
        }

        $this->newLine();
        $this->table(
            ['Seat', 'Id', 'Name', 'Role'],
            $project->fresh()->contributingExperts()
                ->map(fn (Expert $e) => [$e->pivot?->seat, $e->id, $e->name, $e->role])
                ->all(),
        );

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function ids(string $option): array
    {
        if ($this->option($option) === null) {
            return [];
        }

        return array_values(array_map(
            'intval',
            array_filter(array_map('trim', explode(',', $this->option($option))), fn ($id) => $id !== ''),
        ));
    }
}
