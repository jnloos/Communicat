<?php

namespace App\Services\Study\Planning;

use App\Actions\Projects\OpenProject;
use App\Models\Expert;
use App\Models\Project;
use App\Models\ProjectGroup;
use App\Models\StudyWave;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Thesis study, see docs/study.md.
 *
 * Opens the planned runs of a wave as ordinary projects and experts (spec 3).
 * Idempotent: everything is found again by its content-derived uuid, so a
 * second call writes nothing and an interrupted one continues. One transaction
 * per instance, so an abort leaves whole instances only.
 */
final class WaveWriter
{
    /** Names per whereIn query of the foreign-name check. */
    private const NAME_CHUNK = 1000;

    public function __construct(
        private readonly Catalog $catalog,
        private readonly OpenProject $openProject,
    ) {}

    /**
     * Why writing these plans would not give what the manifest describes.
     *
     * @param  list<RunPlan>  $plans
     * @return list<string>
     */
    public function problems(Manifest $manifest, array $plans): array
    {
        return [...$this->catalog->problems($manifest), ...$this->foreignNames($plans)];
    }

    /**
     * @param  (callable(int $scenario): void)|null  $progress  called after each scenario
     *
     * @throws WaveRefused
     */
    public function write(StudyWave $wave, User $owner, ?callable $progress = null): void
    {
        $manifest = $wave->manifest();
        $plans = (new Planner)->plan($manifest, $wave->first_scenario, $wave->last_scenario);

        $problems = $this->problems($manifest, $plans);
        if ($problems !== []) {
            throw new WaveRefused($problems);
        }

        $catalog = DB::transaction(fn () => $this->catalog->ensure($manifest, $plans, $wave->number));
        $groupId = $this->groupId($wave, $owner);

        foreach (collect($plans)->groupBy('scenario') as $scenario => $runs) {
            foreach ($runs->groupBy('block') as $instance) {
                DB::transaction(fn () => $this->writeInstance($instance->all(), $catalog, $owner, $wave->number, $groupId));
            }

            if ($progress !== null) {
                $progress($scenario);
            }
        }
    }

    /**
     * The runs of one instance (scenario × block) and their personas (six, or up to
     * fourteen with a seat table, where the description follows the seat). Public
     * for the preview, which writes one instance and rolls it back.
     *
     * @param  list<RunPlan>  $runs
     */
    public function writeInstance(array $runs, CatalogIds $catalog, User $owner, int $wave, ?int $groupId): void
    {
        $existing = Project::whereIn('uuid', array_map(fn (RunPlan $run) => $run->uuid, $runs))->pluck('uuid')->flip();
        $experts = [];

        foreach ($runs as $run) {
            $seated = array_map(fn (PersonaPlan $persona) => $experts[$persona->uuid] ??= $this->persona($persona, $catalog), $run->seating());

            if ($existing->has($run->uuid)) {
                continue;
            }

            $project = $this->openProject->handle($owner, $run->projectAttributes(), $seated, $catalog->projectTags($run, $wave), $catalog->visibilities, $run->uuid);

            if ($groupId !== null) {
                $project->fileInGroupFor($owner, $groupId);
            }
        }
    }

    /** @throws WaveRefused when the persona exists but no longer says what its uuid promises */
    private function persona(PersonaPlan $plan, CatalogIds $catalog): Expert
    {
        $expert = Expert::where('uuid', $plan->uuid)->first();

        if ($expert === null) {
            $expert = (new Expert)->forceFill(['uuid' => $plan->uuid, 'name' => $plan->name, 'description' => $plan->description]);
            $expert->save();
            $expert->syncAttributeValues($catalog->attributeValues($plan));
            $expert->syncTags($catalog->personaTags($plan));

            return $expert;
        }

        $actual = [$expert->name, $expert->description, $expert->personaAttributes->pluck('pivot.value', 'id')->only(array_keys($catalog->attributeValues($plan)))->all()];
        $wanted = [$plan->name, $plan->description, $catalog->attributeValues($plan)];
        ksort($actual[2]);
        ksort($wanted[2]);

        if ($actual !== $wanted) {
            throw new WaveRefused(["Persona {$plan->uuid} ({$expert->name}) was changed by hand: name, description or attributes differ from its plan."]);
        }

        // Tags reach no prompt and stay writable on a locked persona; add what is missing, change nothing.
        $missing = array_diff_key($catalog->personaTags($plan), $expert->tagSelection());
        if ($missing !== []) {
            $expert->syncTags($missing);
        }

        return $expert;
    }

    /**
     * Planned full names that another persona already carries (a pilot or probe
     * persona): two people of one name in the data could not be told apart.
     *
     * @param  list<RunPlan>  $plans
     * @return list<string>
     */
    private function foreignNames(array $plans): array
    {
        $planned = [];
        foreach ($plans as $run) {
            foreach ($run->personas as $persona) {
                $planned[$persona->name][$persona->uuid] = true;
            }
        }

        $problems = [];
        foreach (array_chunk(array_keys($planned), self::NAME_CHUNK) as $names) {
            foreach (Expert::whereIn('name', $names)->get(['id', 'name', 'uuid']) as $expert) {
                if (! isset($planned[$expert->name][$expert->uuid])) {
                    $problems[] = "{$expert->name} already exists as expert {$expert->id}, which is not this study's persona.";
                }
            }
        }

        return $problems;
    }

    /** The owner's sidebar group of this wave, created once; study:queue --group=<id> takes it. */
    private function groupId(StudyWave $wave, User $owner): int
    {
        if ($wave->group !== null) {
            return $wave->group->id;
        }

        $group = ProjectGroup::firstOrCreate(['user_id' => $owner->id, 'name' => "Studie {$wave->study} · Welle {$wave->number}"]);
        $wave->update(['project_group_id' => $group->id]);

        return $group->id;
    }
}
