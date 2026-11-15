<?php

namespace App\Study\Progress;

use App\Discussion\Metrics\SpokenTurns;
use App\Discussion\Values\JobStatus;
use App\Models\JobLog;
use App\Models\Project;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Services\Tags\TagNames;
use App\Study\Jobs\RunStudyProject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Study-only, removable: see app/Study/README.md.
 *
 * Where a study batch stands, for the progress page: how many runs are done,
 * how fast turns come in, whether the workers still move, what went wrong in the
 * last hour. Operations only -- it never reads a share or an effect, so looking
 * at it during a batch cannot steer when to stop.
 *
 * A study cell is a project tagged with the `focus profile` category; its grid
 * position comes from that tag and `duo profile`, its batch from `wave`.
 */
class StudyProgress
{
    public function __construct(private readonly SpokenTurns $spokenTurns) {}

    public function read(?int $waveId = null): array
    {
        $categories = $this->categories();

        $missing = array_keys(array_filter(['focus' => $categories['focus'], 'duo' => $categories['duo']], fn ($category) => $category === null));

        if ($missing !== []) {
            return ['missing' => array_map(fn (string $key) => config('study.tags')[$key], $missing)];
        }

        $tags = $this->tagsOfCells($categories);

        if ($waveId !== null) {
            $tags = $tags->filter(fn (array $cell) => ($cell['wave'] ?? null) === $waveId);
        }

        $projects = Project::whereKey($tags->keys()->all())->get(['id', 'pipeline', 'turn_budget']);
        $spoken = $this->spokenTurns->counts($projects);
        $givenUp = $this->givenUpProjectIds();

        $runs = $projects->map(fn (Project $project) => [
            'pipeline' => $project->pipeline,
            'focus' => $tags[$project->id]['focus'],
            'duo' => $tags[$project->id]['duo'] ?? null,
            'remaining' => max(0, (int) $project->turn_budget - ($spoken[$project->id] ?? 0)),
            'state' => $this->state($spoken[$project->id] ?? 0, (int) $project->turn_budget, isset($givenUp[$project->id])),
        ]);

        $ids = $projects->modelKeys();
        $turnsLastHour = JobLog::whereIn('project_id', $ids)->whereNotNull('words')->where('finished_at', '>=', now()->subHour())->count();
        $lastTurnAt = JobLog::whereIn('project_id', $ids)->max('finished_at');
        $remaining = $runs->where('state', '!=', 'given_up')->sum('remaining');

        return [
            'missing' => [],
            'waves' => $categories['wave']?->values->pluck('name', 'id')->all() ?? [],
            'rows' => $categories['focus']->values->pluck('name', 'id')->all(),
            'columns' => $categories['duo']->values->pluck('name', 'id')->all(),
            'grids' => $this->grids($runs),
            'totals' => array_merge(['total' => $runs->count(), 'done' => 0, 'started' => 0, 'open' => 0, 'given_up' => 0], $runs->countBy('state')->all()),
            'turnsPerHour' => $turnsLastHour,
            'hoursLeft' => $turnsLastHour > 0 ? $remaining / $turnsLastHour : null,
            'lastTurnAt' => $lastTurnAt,
            'remaining' => $remaining,
            'stale' => $remaining > 0 && ($lastTurnAt === null || now()->diffInMinutes($lastTurnAt, true) > config('study.stale_after_minutes')),
            'disruptions' => $this->disruptions($ids),
            'queued' => DB::table('jobs')->where('queue', RunStudyProject::CONNECTION)->count(),
            'failedJobs' => DB::table('failed_jobs')->where('queue', RunStudyProject::CONNECTION)->latest('failed_at')->limit(20)->get(['payload', 'exception', 'failed_at'])
                ->map(fn ($row) => ['project' => $this->projectIdIn($row->payload), 'reason' => strtok($row->exception, "\n"), 'at' => $row->failed_at])
                ->all(),
        ];
    }

    /** @return array<string, TagCategory|null> config key => category, matched by name like everywhere else */
    private function categories(): array
    {
        $all = TagCategory::ofScope(TagScope::Project)
            ->with(['values' => fn ($query) => $query->orderBy('position')->orderBy('id')])
            ->get();

        return array_map(
            fn (string $name) => $all->first(fn (TagCategory $category) => TagNames::same($category->name, $name)),
            config('study.tags'),
        );
    }

    /** @return Collection<int, array<string, int>> project id => [config key => tag value id], cells only */
    private function tagsOfCells(array $categories): Collection
    {
        $keyOf = collect($categories)->filter()->mapWithKeys(fn (TagCategory $category, string $key) => [$category->id => $key]);

        return DB::table('taggables')
            ->where('taggable_type', (new Project)->getMorphClass())
            ->whereIn('tag_category_id', $keyOf->keys())
            ->get(['taggable_id', 'tag_category_id', 'tag_value_id'])
            ->groupBy('taggable_id')
            ->map(fn (Collection $rows) => $rows->mapWithKeys(fn ($row) => [$keyOf[$row->tag_category_id] => (int) $row->tag_value_id])->all())
            ->filter(fn (array $cell) => isset($cell['focus']));
    }

    private function state(int $spoken, int $budget, bool $givenUp): string
    {
        return match (true) {
            $spoken >= $budget => 'done',
            $givenUp => 'given_up',
            $spoken > 0 => 'started',
            default => 'open',
        };
    }

    /** pipeline => focus value id => duo value id => counts per state */
    private function grids(Collection $runs): array
    {
        $grids = [];

        foreach ($runs as $run) {
            $cell = &$grids[$run['pipeline']][$run['focus']][$run['duo']];
            $cell ??= ['total' => 0, 'done' => 0, 'started' => 0, 'open' => 0, 'given_up' => 0];
            $cell['total']++;
            $cell[$run['state']]++;
            unset($cell);
        }

        ksort($grids);

        return $grids;
    }

    /** "interrupted · RateLimitedException" => count, over the last hour */
    private function disruptions(array $projectIds): array
    {
        return JobLog::whereIn('project_id', $projectIds)
            ->whereIn('status', [JobStatus::Failed, JobStatus::Interrupted])
            ->where('finished_at', '>=', now()->subHour())
            ->get(['status', 'error'])
            ->countBy(fn (JobLog $log) => $log->status->value.' · '.strtok((string) $log->error, ':'))
            ->sortDesc()
            ->all();
    }

    /** @return array<int, true> */
    private function givenUpProjectIds(): array
    {
        return DB::table('failed_jobs')->where('queue', RunStudyProject::CONNECTION)->pluck('payload')
            ->map(fn (string $payload) => $this->projectIdIn($payload))
            ->filter()
            ->mapWithKeys(fn (int $id) => [$id => true])
            ->all();
    }

    /** The project id inside a serialised RunStudyProject payload. */
    private function projectIdIn(string $payload): ?int
    {
        return preg_match('/projectId\\\\?";i:(\d+);/', $payload, $match) ? (int) $match[1] : null;
    }
}
