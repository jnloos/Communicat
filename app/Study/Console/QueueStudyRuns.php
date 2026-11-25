<?php

namespace App\Study\Console;

use App\Discussion\Metrics\SpokenTurns;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Study\Control\GiveUp;
use App\Study\Control\PauseFlag;
use App\Study\Control\QueuedRuns;
use App\Study\Jobs\RunStudyProject;
use Illuminate\Bus\UniqueLock;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Queues every unfinished run of a batch on the `study` queue, one RunStudyProject
 * each. Rerunning it is safe: finished and superseded runs are skipped, and a run
 * already queued is not queued again (the job is unique per project until it starts).
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

    public function handle(SpokenTurns $spokenTurns, GiveUp $giveUp, PauseFlag $pause, QueuedRuns $queuedRuns): int
    {
        $projects = $this->projects();

        if ($projects === null) {
            return self::FAILURE;
        }

        $spoken = $spokenTurns->counts($projects);
        $superseded = $projects->whereIn('id', $giveUp->markedAmong($projects->modelKeys()));
        $candidates = $projects->whereNotIn('id', $superseded->modelKeys());
        $unbudgeted = $candidates->whereNull('turn_budget');
        $open = $candidates->whereNotNull('turn_budget')
            ->filter(fn (Project $project) => ($spoken[$project->id] ?? 0) < $project->turn_budget);

        $pending = $queuedRuns->ids();
        $queued = count(array_filter($this->ordered($open), fn (Project $project) => $this->queue($project->id, $pending)));

        foreach ($unbudgeted as $project) {
            $this->warn("Skipped project {$project->id}: no turn budget, a study job would never end.");
        }

        if ($pause->active()) {
            $this->warn('Study is paused: the runs are queued, but no worker takes them before study:resume.');
        }

        $this->table(['', ''], [
            ['Queued', $queued],
            ['Already queued', $open->count() - $queued],
            ['Already finished', $projects->count() - $open->count() - $unbudgeted->count() - $superseded->count()],
            ['Skipped, superseded', $superseded->count()],
            ['Skipped, no budget', $unbudgeted->count()],
            ['Order', $this->option('order-seed') === null ? 'by id' : 'shuffled, seed '.$this->option('order-seed')],
        ]);

        return self::SUCCESS;
    }

    /**
     * Takes the job's unique lock itself, which dispatch() would take silently, so the
     * summary can tell a queued run from one whose chain is already queued or running.
     * A lock without a job row is stale (its job failed while being reserved): released,
     * so the run is queued again. A running job keeps its row until its successor is queued.
     * shortcut: skips PendingDispatch's unique-job context, which only helps a job that cannot
     * be unserialized (this one holds an int); use dispatch() again if the job ever holds a model.
     */
    /** @param  array<int, true>  $pending  runs with a job row */
    private function queue(int $projectId, array $pending): bool
    {
        $job = new RunStudyProject($projectId);
        $lock = new UniqueLock(app(Cache::class));

        if (! $lock->acquire($job)) {
            if (isset($pending[$projectId])) {
                return false;
            }

            $this->warn("Run {$projectId}: released a stale unique lock (no job row).");
            $lock->release($job);

            if (! $lock->acquire($job)) {
                return false;
            }
        }

        Bus::dispatch($job);

        return true;
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
