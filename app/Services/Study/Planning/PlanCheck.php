<?php

namespace App\Services\Study\Planning;

use App\Models\Expert;
use App\Models\PersonaAttribute;
use App\Models\Project;
use App\Models\StudyWave;
use App\Models\TagValue;
use App\Services\Tags\TagNames;
use Illuminate\Support\Collection;

/**
 * Thesis study, see docs/study.md.
 *
 * Recomputes the plan of every wave of a study and compares it with what the
 * database holds (spec 4, study:check). Each wave is planned from its own
 * manifest copy, and the newest copy must give the same runs for every older
 * scenario: that is the proof that a later wave changed nothing an earlier one
 * planned.
 */
final class PlanCheck
{
    private const UUID_CHUNK = 500;

    /** @var list<array{0: string, 1: string, 2: string, 3: string}> run key, what, expected, actual */
    private array $deviations = [];

    /**
     * @return array{waves: int, runs: int, deviations: list<array{0: string, 1: string, 2: string, 3: string}>, commits: list<array{0: string, 1: int, 2: int}>}
     */
    public function run(string $study): array
    {
        $this->deviations = [];
        $waves = StudyWave::where('study', $study)->orderBy('number')->get();
        $planned = $this->plannedRuns($waves);
        $projects = $this->projects(array_keys($planned));
        $definitions = PersonaAttribute::ordered()->get();

        foreach ($planned as $uuid => [$run, $wave]) {
            $project = $projects->get($uuid);
            $project === null
                ? $this->deviate($run->key, 'project', 'exists', 'missing')
                : $this->compare($run, $wave, $project, $definitions);
        }

        $this->strays($study, $planned);

        return [
            'waves' => $waves->count(),
            'runs' => count($planned),
            'deviations' => $this->deviations,
            'commits' => $this->commits($projects),
        ];
    }

    /** @return array<string, array{0: RunPlan, 1: int}> uuid => [plan, wave number] */
    private function plannedRuns(Collection $waves): array
    {
        $planner = new Planner;
        $planned = [];

        foreach ($waves as $wave) {
            foreach ($planner->plan($wave->manifest(), $wave->first_scenario, $wave->last_scenario) as $run) {
                $planned[$run->uuid] = [$run, $wave->number];
            }
        }

        $newest = $waves->last();
        if ($newest !== null) {
            foreach ($planner->plan($newest->manifest(), 1, $waves->max('last_scenario')) as $run) {
                $own = $planned[$run->uuid][0] ?? null;
                if ($own === null || $own->toArray() !== $run->toArray()) {
                    $this->deviate($run->key, "plan of wave {$newest->number}'s manifest", 'as its own wave planned it', 'differs');
                }
            }
        }

        return $planned;
    }

    private function compare(RunPlan $run, int $wave, Project $project, Collection $definitions): void
    {
        foreach ($run->projectAttributes() as $column => $expected) {
            if ($column !== 'title') {
                $this->same($run->key, $column, $expected, $project->{$column});
            }
        }

        $experts = $project->experts;
        $this->same($run->key, 'expert uuids by seat', array_map(fn (PersonaPlan $persona) => $persona->uuid, $run->seating()), $experts->pluck('uuid')->all());

        foreach ($run->seating() as $persona) {
            $expert = $experts->firstWhere('uuid', $persona->uuid);
            if ($expert !== null) {
                $this->comparePersona($run->key, $persona, $expert);
            }
        }

        $this->tags($run->key, config('study.tags'), $run->tags() + ['wave' => (string) $wave], $project->tagValues);
        $this->same($run->key, 'visibilities', $this->expectedVisibilities($definitions), $this->visibilities($project, $definitions));

        if ($project->run_config !== null) {
            $this->same($run->key, 'run_config.cast names', array_map(fn (PersonaPlan $persona) => $persona->name, $run->seating()), array_column($project->run_config['cast'] ?? [], 'name'));
            $this->same($run->key, 'run_config.model', $run->model, $project->run_config['model']['key'] ?? null);
        }
    }

    private function comparePersona(string $key, PersonaPlan $persona, Expert $expert): void
    {
        $label = "persona {$persona->name}";
        $this->same($key, "{$label} name", $persona->name, $expert->name);
        $this->same($key, "{$label} description", $persona->description, $expert->description);

        foreach ($persona->attributes as $name => $value) {
            $held = $expert->personaAttributes->first(fn (PersonaAttribute $attribute) => TagNames::same($attribute->name, $name));
            $this->same($key, "{$label} attribute {$name}", $value, $held?->pivot->value);
        }

        $this->tags($key, config('study.persona_tags'), $persona->tags(), $expert->tagValues, "{$label} ");
    }

    /**
     * @param  array<string, string>  $names  config key => category name
     * @param  array<string, string>  $expected  config key => value
     * @param  Collection<int, TagValue>  $actual
     */
    private function tags(string $key, array $names, array $expected, Collection $actual, string $prefix = ''): void
    {
        foreach ($expected as $tag => $value) {
            $found = $actual->first(fn (TagValue $tagValue) => TagNames::same($tagValue->category->name, $names[$tag]));
            $this->same($key, "{$prefix}tag {$names[$tag]}", $value, $found?->name);
        }
    }

    /** @return array<string, string> attribute name => level */
    private function expectedVisibilities(Collection $definitions): array
    {
        return $definitions->mapWithKeys(fn (PersonaAttribute $definition) => [
            $definition->name => collect(ManifestProblems::ATTRIBUTES)->contains(fn (string $name) => TagNames::same($name, $definition->name)) ? 'all' : 'none',
        ])->all();
    }

    /** @return array<string, string> */
    private function visibilities(Project $project, Collection $definitions): array
    {
        $levels = $project->attributeVisibilities($definitions);

        return $definitions->mapWithKeys(fn (PersonaAttribute $definition) => [$definition->name => $levels[$definition->id]->value])->all();
    }

    /** Projects tagged with this study that no wave planned. */
    private function strays(string $study, array $planned): void
    {
        $categoryIds = StudyWave::projectCategoryIds(config('study.tags.study'));

        Project::whereHas('tagValues', fn ($query) => $query->whereIn('tag_values.tag_category_id', $categoryIds)->where('tag_values.name', $study))
            ->get(['id', 'uuid', 'title'])
            ->reject(fn (Project $project) => isset($planned[$project->uuid]))
            ->each(fn (Project $project) => $this->deviate("project {$project->id}", 'study project', 'in a plan', "not in any plan ({$project->title})"));
    }

    /** @return list<array{0: string, 1: int, 2: int}> commit, started runs, dirty runs */
    private function commits(Collection $projects): array
    {
        return $projects->filter(fn (Project $project) => $project->run_config !== null)
            ->groupBy(fn (Project $project) => $project->run_config['provenance']['commit'] ?? 'unknown')
            ->map(fn (Collection $runs, string $commit) => [$commit, $runs->count(), $runs->filter(fn (Project $project) => ($project->run_config['provenance']['dirty'] ?? false) === true)->count()])
            ->values()
            ->all();
    }

    /** @return Collection<string, Project> uuid => project with everything compared */
    private function projects(array $uuids): Collection
    {
        return collect(array_chunk($uuids, self::UUID_CHUNK))
            ->flatMap(fn (array $chunk) => Project::whereIn('uuid', $chunk)
                ->with(['experts.personaAttributes', 'experts.tagValues.category', 'tagValues.category', 'attributeVisibilityRows'])
                ->get())
            ->keyBy('uuid');
    }

    private function same(string $key, string $what, mixed $expected, mixed $actual): void
    {
        // Scalars compare as text: the database may hand back "30" for 30.
        $differs = is_scalar($expected) && is_scalar($actual) ? (string) $expected !== (string) $actual : $expected !== $actual;

        if ($differs) {
            $this->deviate($key, $what, self::show($expected), self::show($actual));
        }
    }

    private function deviate(string $key, string $what, string $expected, string $actual): void
    {
        $this->deviations[] = [$key, $what, $expected, $actual];
    }

    private static function show(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : json_encode($value, JSON_UNESCAPED_UNICODE);
    }
}
