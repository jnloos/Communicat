<?php

namespace App\Study\Console;

use App\Discussion\Metrics\SpokenTurns;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Study\Jobs\RunStudyProject;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Queues every unfinished run of a batch on the `study` queue, one RunStudyProject
 * each. Rerunning it is safe: finished runs are skipped, and a job for a run that
 * is already queued finds the budget spent or the run locked and does nothing.
 *
 * --order-seed mixes the order reproducibly, so time on the clock is not tied to
 * a condition; record the seed with the batch.
 */
class QueueStudyRuns extends Command
{
    protected $signature = 'study:queue
        {projects?* : Project ids}
        {--group= : Every project in this sidebar group}
        {--order-seed= : Shuffle the queue order with this seed; without it, by id}';

    protected $description = 'Queue the unfinished study runs on the study queue.';

    public function handle(SpokenTurns $spokenTurns): int
    {
        $projects = $this->projects();

        if ($projects === null) {
            return self::FAILURE;
        }

        $spoken = $spokenTurns->counts($projects);
        $unbudgeted = $projects->whereNull('turn_budget');
        $open = $projects->whereNotNull('turn_budget')
            ->filter(fn (Project $project) => ($spoken[$project->id] ?? 0) < $project->turn_budget);

        foreach ($this->ordered($open) as $project) {
            RunStudyProject::dispatch($project->id);
        }

        foreach ($unbudgeted as $project) {
            $this->warn("Skipped project {$project->id}: no turn budget, a study job would never end.");
        }

        $this->table(['', ''], [
            ['Queued', $open->count()],
            ['Already finished', $projects->count() - $open->count() - $unbudgeted->count()],
            ['Skipped, no budget', $unbudgeted->count()],
            ['Order', $this->option('order-seed') === null ? 'by id' : 'shuffled, seed '.$this->option('order-seed')],
        ]);

        return self::SUCCESS;
    }

    /** @return Collection<int, Project>|null */
    private function projects(): ?Collection
    {
        $ids = array_map('intval', $this->argument('projects'));
        $groupId = $this->option('group');

        if ($ids === [] && $groupId === null) {
            $this->error('Name project ids or a --group.');

            return null;
        }

        $projects = Project::whereKey($ids)->get();

        if ($projects->count() !== count(array_unique($ids))) {
            $this->error('Unknown project id: '.implode(', ', array_diff($ids, $projects->modelKeys())));

            return null;
        }

        if ($groupId !== null) {
            $group = ProjectGroup::find($groupId);

            if ($group === null) {
                $this->error("Unknown group {$groupId}.");

                return null;
            }

            $projects = $projects->merge($group->projects()->get());
        }

        return $projects->unique('id')->sortBy('id')->values();
    }

    /** @param Collection<int, Project> $projects */
    private function ordered(Collection $projects): array
    {
        $seed = $this->option('order-seed');
        $list = $projects->sortBy('id')->values()->all();

        if ($seed === null) {
            return $list;
        }

        return (new Randomizer(new Xoshiro256StarStar(hash('sha256', (string) $seed, binary: true))))->shuffleArray($list);
    }
}
