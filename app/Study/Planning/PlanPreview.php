<?php

namespace App\Study\Planning;

use App\Discussion\Support\PromptRenderer;
use App\Models\Expert;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Study-only, removable: see app/Study/README.md.
 *
 * What a wave would write, before it does (spec 4): runs and personas new or
 * present, how the focus seat and the focus subgroup fall, how often each first
 * name is used, and the real prompt of one persona per scenario. No costs.
 */
final class PlanPreview
{
    private const UUID_CHUNK = 1000;

    public function __construct(
        private readonly Catalog $catalog,
        private readonly WaveWriter $writer,
        private readonly PromptRenderer $prompts,
    ) {}

    /** @return array{problems: list<string>, runs: array, personas: array, seats: array, subgroups: array, names: array, duplicate_full_names: int, prompts: array<int, string>} */
    public function of(PreparedWave $wave, User $owner, bool $withPrompts = true): array
    {
        $plans = collect($wave->plans);
        $personas = $plans->flatMap(fn (RunPlan $run) => array_values($run->personas))->unique('uuid')->values();

        return [
            'problems' => $wave->problems,
            'runs' => $this->runs($plans),
            'personas' => $this->present($personas->pluck('uuid')->all(), Expert::class),
            'seats' => $this->seats($plans),
            'subgroups' => $this->subgroups($plans),
            'names' => $wave->manifest === null ? [] : $this->names($wave->manifest, $personas),
            'duplicate_full_names' => $this->duplicateFullNames($plans),
            'prompts' => $withPrompts && $wave->isAcceptable() ? $this->prompts($wave, $owner) : [],
        ];
    }

    /**
     * The preview's numbers as titled tables, the one layout study:plan and the
     * planning page both show.
     *
     * @return list<array{title: string, columns: list<string>, rows: list<list<int|float|string>>}>
     */
    public static function tables(array $preview): array
    {
        if ($preview['runs']['total'] === 0) {
            return [];
        }

        $perPipeline = fn (array $byPipeline) => collect($byPipeline)->map(fn (array $counts, string $pipeline) => [$pipeline, ...array_values($counts)])->values()->all();
        $profiles = collect($preview['subgroups'])->flatMap(fn (array $counts) => array_keys($counts))->unique()->sort()->values()->all();
        $all = __('study::plan.all');

        return [
            ['title' => __('study::plan.runs'), 'columns' => [__('study::plan.pipeline'), __('study::plan.total'), __('study::plan.new'), __('study::plan.existing')],
                'rows' => [...$perPipeline($preview['runs']['per_pipeline']), [$all, $preview['runs']['total'], $preview['runs']['new'], $preview['runs']['existing']]]],
            ['title' => __('study::plan.personas'), 'columns' => [__('study::plan.total'), __('study::plan.new'), __('study::plan.existing')],
                'rows' => [array_values($preview['personas'])]],
            ['title' => __('study::plan.seats'), 'columns' => ['', ...array_map(fn (int $seat) => __('study::plan.seat', ['seat' => $seat]), [1, 2, 3])],
                'rows' => [
                    ...$perPipeline($preview['seats']['per_pipeline']),
                    [$all, ...array_values($preview['seats']['total'])],
                    [__('study::plan.per_cell'), ...array_map(fn (array $range) => "{$range['min']}–{$range['max']}", array_values($preview['seats']['per_cell']))],
                ]],
            ['title' => __('study::plan.subgroups'), 'columns' => ['', ...$profiles],
                'rows' => collect($preview['subgroups'])->map(fn (array $counts, string $subgroup) => [$subgroup, ...array_map(fn ($profile) => $counts[$profile] ?? 0, $profiles)])->values()->all()],
            ['title' => __('study::plan.names'), 'columns' => [__('study::plan.pool'), 'Min', 'Median', 'Max'],
                'rows' => collect($preview['names'])->map(fn (array $counts, string $pool) => [$pool, ...array_values($counts)])->values()->all()],
        ];
    }

    /** @return array{total: int, new: int, existing: int, per_pipeline: array<string, array{total: int, new: int, existing: int}>} */
    private function runs(Collection $plans): array
    {
        $existing = array_flip($this->existing($plans->pluck('uuid')->all(), Project::class));
        $count = fn (Collection $runs) => [
            'total' => $runs->count(),
            'new' => $runs->reject(fn (RunPlan $run) => isset($existing[$run->uuid]))->count(),
            'existing' => $runs->filter(fn (RunPlan $run) => isset($existing[$run->uuid]))->count(),
        ];

        return $count($plans) + ['per_pipeline' => $plans->groupBy('pipeline')->map($count)->all()];
    }

    /** @return array{total: int, new: int, existing: int} */
    private function present(array $uuids, string $model): array
    {
        $existing = count($this->existing($uuids, $model));

        return ['total' => count($uuids), 'new' => count($uuids) - $existing, 'existing' => $existing];
    }

    /** @return list<string> the uuids that exist already */
    private function existing(array $uuids, string $model): array
    {
        return collect(array_chunk($uuids, self::UUID_CHUNK))
            ->flatMap(fn (array $chunk) => $model::whereIn('uuid', $chunk)->pluck('uuid'))
            ->all();
    }

    /**
     * Focus seat counts overall and per pipeline, and per seat the fewest and most
     * times it falls to the focus within one cell (focus × duo profile). Seats are
     * drawn per run and never balanced; this only makes a skew visible.
     */
    private function seats(Collection $plans): array
    {
        $count = fn (Collection $runs) => array_replace([1 => 0, 2 => 0, 3 => 0], $runs->countBy(fn (RunPlan $run) => $run->seatOf('focus'))->all());
        $cells = $plans->groupBy(fn (RunPlan $run) => $run->focusProfile().'/'.$run->duoProfile())->map($count);

        return [
            'total' => $count($plans),
            'per_pipeline' => $plans->groupBy('pipeline')->map($count)->all(),
            'per_cell' => collect([1, 2, 3])->mapWithKeys(fn (int $seat) => [$seat => [
                'min' => $cells->min(fn (array $seats) => $seats[$seat]) ?? 0,
                'max' => $cells->max(fn (array $seats) => $seats[$seat]) ?? 0,
            ]])->all(),
        ];
    }

    /** @return array<string, array<string, int>> focus subgroup => focus profile => runs */
    private function subgroups(Collection $plans): array
    {
        return $plans->groupBy(fn (RunPlan $run) => $run->personas['focus']->subgroup)
            ->map(fn (Collection $runs) => $runs->countBy(fn (RunPlan $run) => $run->focusProfile())->sortKeys()->all())
            ->sortKeys()
            ->all();
    }

    /**
     * Personas per first-name id, summarised per gender × subgroup; names never
     * drawn count as 0, so a pool used unevenly shows.
     *
     * @return array<string, array{min: int, median: float, max: int}> "G+ DE" => …
     */
    private function names(Manifest $manifest, Collection $personas): array
    {
        $used = $personas->countBy(fn (PersonaPlan $persona) => $persona->firstNameId);
        $summary = [];

        foreach (Pot::cases() as $pot) {
            foreach ($manifest->subgroupsOf($pot->ethnicity()) as $subgroup) {
                $counts = collect($manifest->firstNames($subgroup, $pot->gender()))->map(fn (array $name) => $used[$name['id']] ?? 0);
                $summary["{$pot->gender()} {$subgroup}"] = ['min' => $counts->min(), 'median' => $counts->median(), 'max' => $counts->max()];
            }
        }

        return $summary;
    }

    /** Full names that appear in more than one instance; the planner promises 0. */
    private function duplicateFullNames(Collection $plans): int
    {
        return $plans->flatMap(fn (RunPlan $run) => array_map(fn (PersonaPlan $persona) => [$persona->name, "{$run->scenario}:{$run->block}"], array_values($run->personas)))
            ->unique(fn (array $pair) => implode('|', $pair))
            ->countBy(fn (array $pair) => $pair[0])
            ->filter(fn (int $instances) => $instances > 1)
            ->count();
    }

    /**
     * Per scenario, what the focus persona of block 1, hh, first pipeline reads
     * about itself, the others and the topic. Rendered from a real project: the
     * instance is written in a transaction and rolled back, like the bundle
     * preview, so the text is the real one and no persona gets locked.
     *
     * @return array<int, string> scenario => prompt text
     */
    private function prompts(PreparedWave $wave, User $owner): array
    {
        $texts = [];

        foreach (collect($wave->plans)->where('block', 1)->groupBy('scenario') as $scenario => $instance) {
            $run = $instance->first(fn (RunPlan $run) => $run->focusStatus.$run->duoStatus === 'hh');

            DB::beginTransaction();
            try {
                $catalog = $this->catalog->ensure($wave->manifest, $instance->all(), $wave->number);
                $this->writer->writeInstance($instance->all(), $catalog, $owner, $wave->number, null);
                $texts[$scenario] = $this->render(Project::where('uuid', $run->uuid)->firstOrFail(), $run->personas['focus']->uuid);
            } finally {
                DB::rollBack();
            }
        }

        return $texts;
    }

    /** The persona, participants and project partials with the variables ThinkAsSpeaker passes. */
    private function render(Project $project, string $focusUuid): string
    {
        $expert = $project->contributingExperts()->firstWhere('uuid', $focusUuid);

        return implode("\n\n", [
            $this->prompts->render('prompts.partials.persona', ['expert' => $expert, 'project' => $project]),
            $this->prompts->render('prompts.partials.participants', ['project' => $project] + $this->prompts->participants($project)),
            $this->prompts->render('prompts.partials.project', ['project' => $project]),
        ]);
    }
}
