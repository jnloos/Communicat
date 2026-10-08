<?php

namespace App\Livewire\Statistics;

use App\Discussion\Metrics\References\AgentReferences;
use App\Discussion\Metrics\SeriesProjects;
use App\Discussion\Metrics\SeriesStatistics;
use App\Discussion\Pipelines\PipelineRegistry;
use App\Discussion\Values\ComparisonDisplay;
use App\Discussion\Values\ComparisonView;
use App\Discussion\Values\Metric;
use App\Discussion\Values\ModelConfig;
use App\Discussion\Values\SeriesColor;
use App\Discussion\Values\SeriesDefinition;
use App\Discussion\Values\SeriesLine;
use App\Discussion\Values\SeriesResult;
use App\Livewire\Concerns\NeedsConfirmation;
use App\Models\Comparison;
use App\Models\Expert;
use App\Models\TagCategory;
use App\Models\TagScope;
use App\Models\TagValue;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One comparison, edited in place: its series (project filter + reference),
 * the view that applies to all of them, and — below — the result. Every edit
 * is normalised through SeriesDefinition/ComparisonView and saved at once; a
 * comparison has no draft state.
 */
class ComparisonEditor extends Component
{
    use NeedsConfirmation;

    /** The page renamed this comparison; render again so the heading follows. */
    protected $listeners = ['comparison-renamed' => '$refresh'];

    #[Locked]
    public int $comparisonId;

    /** @var list<array<string, mixed>> SeriesDefinition::toArray() per series */
    public array $series = [];

    /** @var array<string, mixed> ComparisonView::toArray() */
    public array $viewSettings = [];

    /** Tag values this comparison named that have since been deleted. */
    #[Locked]
    public int $droppedTagValues = 0;

    public function mount(int $comparisonId): void
    {
        $comparison = $this->comparison($comparisonId);
        $definitions = $comparison->seriesDefinitions();

        // Ids only: a template's unknown tag names are no deletion. An id 0 was
        // the placeholder of older template comparisons, never a real value.
        $named = array_values(array_filter(
            array_unique(array_merge([], ...array_map(fn (SeriesDefinition $d) => $d->tagValueIds(), $definitions))),
            fn (int $id) => $id > 0,
        ));
        $existing = TagValue::whereKey($named)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $missing = array_values(array_diff($named, $existing));

        $this->comparisonId = $comparison->id;
        $this->droppedTagValues = count($missing);
        $this->series = array_map(fn (SeriesDefinition $d) => $d->withoutTagValues($missing)->toArray(), $definitions);
        $this->viewSettings = $comparison->comparisonView()->toArray();
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'series') || str_starts_with($property, 'viewSettings')) {
            $this->persist();
        }
    }

    public function addSeries(): void
    {
        $this->series[] = (new SeriesDefinition($this->defaultLabel($this->definitions())))->toArray();
        $this->persist();
    }

    public function confirmRemoveSeries(int $index): void
    {
        $definition = $this->withDefaultLabels($this->definitions())[$index] ?? null;

        if ($definition === null) {
            return;
        }

        $this->confirmTitle = __('comparisons.confirm.remove_series.title');
        $this->confirmMessage = __('comparisons.confirm.remove_series.message', ['label' => $definition->label]);
        $this->needsConfirmation('removeSeries', $index);
    }

    public function removeSeries(int $index): void
    {
        unset($this->series[$index]);
        $this->persist();
    }

    /** Removes one tag name the series' template knew and this database does not. */
    public function removeUnresolvedTag(int $index, int $tag): void
    {
        $definition = $this->definitions()[$index] ?? null;

        if ($definition === null) {
            return;
        }

        $this->series[$index] = $definition->withoutUnresolvedTag($tag)->toArray();
        $this->persist();
    }

    /** Normalises the form state, writes it back so the form shows what is stored, and saves it. */
    private function persist(): void
    {
        $this->series = array_map(fn (SeriesDefinition $d) => $d->toArray(), $this->definitions());
        $this->viewSettings = ComparisonView::fromArray($this->viewSettings)->toArray();

        $this->comparison($this->comparisonId)->update(['series' => $this->series, 'view' => $this->viewSettings]);
    }

    /** @return list<SeriesDefinition> */
    private function definitions(): array
    {
        return SeriesDefinition::listFrom($this->series);
    }

    /** Through the user's own comparisons: someone else's does not exist here. */
    private function comparison(int $comparisonId): Comparison
    {
        return auth()->user()->comparisons()->findOrFail($comparisonId);
    }

    public function render(): mixed
    {
        $definitions = $this->definitions();
        $view = ComparisonView::fromArray($this->viewSettings);
        $matches = array_map(fn (SeriesDefinition $d) => app(SeriesProjects::class)->for(auth()->user(), $d), $definitions);
        $results = array_map(
            fn (SeriesDefinition $d, $projects) => app(SeriesStatistics::class)->compute($d, $view, $projects),
            $this->withDefaultLabels($definitions),
            $matches,
        );
        $colors = SeriesColor::assign(array_map(fn (SeriesDefinition $d) => $d->color, $definitions));
        $lines = $this->displayLines($results, $colors);
        $charts = new ResultCharts($lines, $results, $view->metric);
        // The curve colours each line, every other display each series.
        $drawn = array_map('strval', $view->display === ComparisonDisplay::Trajectory ? array_column($lines, 'lineColor') : $colors);

        return view('livewire.statistics.comparison-editor', [
            'name' => $this->comparison($this->comparisonId)->name,
            'definitions' => $definitions,
            'emptiedSeries' => $this->emptiedSeries($this->withDefaultLabels($definitions)),
            'unresolvedSeries' => array_values(array_map(
                fn (SeriesDefinition $d) => $d->label,
                array_filter($this->withDefaultLabels($definitions), fn (SeriesDefinition $d) => $d->hasUnresolvedTags()),
            )),
            'matches' => $matches,
            'results' => $results,
            'lines' => $lines,
            'seriesColors' => $colors,
            ...$this->chartData($view->display, $charts),
            'paletteRepeats' => count(array_unique($drawn)) < count($drawn),
            'rollingWindow' => (int) config('discussion.share_window'),
            'comparisonView' => $view,
            'metrics' => Metric::cases(),
            'displays' => ComparisonDisplay::cases(),
            'projectCategories' => TagCategory::ofScope(TagScope::Project)->with('values')->get(),
            'personaCategories' => TagCategory::ofScope(TagScope::Persona)->with('values')->get(),
            'pipelines' => app(PipelineRegistry::class)->options(),
            'models' => ModelConfig::options(),
            'expertNames' => Expert::query()->distinct()->orderBy('name')->pluck('name'),
            'referenceTypes' => array_keys(AgentReferences::TYPES),
        ]);
    }

    /**
     * What the shown display draws, and nothing for the others.
     *
     * @return array<string, mixed>
     */
    private function chartData(ComparisonDisplay $display, ResultCharts $charts): array
    {
        $none = ['barGroups' => null, 'barScale' => null, 'barTicks' => null, 'equalShare' => null, 'pies' => null, 'trajectoryRows' => null];

        return match ($display) {
            ComparisonDisplay::Table => [],
            ComparisonDisplay::Bars => [
                'barGroups' => $charts->barGroups(),
                'barScale' => $charts->barScale(),
                'barTicks' => $charts->barTicks(),
                'equalShare' => $charts->equalShare(),
            ],
            ComparisonDisplay::Pie => ['pies' => $charts->pies(), 'equalShare' => $charts->equalShare()],
            ComparisonDisplay::Trajectory => ['trajectoryRows' => $charts->trajectoryRows()],
        } + $none;
    }

    /**
     * Series whose project filter names a category none of whose chosen values
     * exists any more. They match no run (never all of them), and the notice
     * says which ones.
     *
     * @param  list<SeriesDefinition>  $definitions  already carrying their display labels
     * @return list<string>
     */
    private function emptiedSeries(array $definitions): array
    {
        $named = array_merge([], ...array_map(fn (SeriesDefinition $d) => $d->projectTags->valueIds(), $definitions));
        $existing = TagValue::whereKey($named)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_map(
            fn (SeriesDefinition $d) => $d->label,
            array_filter($definitions, fn (SeriesDefinition $d) => $d->projectTags->isUnsatisfiable($existing)),
        ));
    }

    /**
     * A label may be stored empty; the result then shows the next free default
     * name, so no legend, row or tooltip renders blank.
     *
     * @param  list<SeriesDefinition>  $definitions
     * @return list<SeriesDefinition>
     */
    private function withDefaultLabels(array $definitions): array
    {
        $labelled = [];

        foreach ($definitions as $definition) {
            $labelled[] = $definition->label === ''
                ? $definition->withLabel($this->defaultLabel([...$definitions, ...$labelled]))
                : $definition;
        }

        return $labelled;
    }

    /**
     * "Series N" with N one above the highest default number in use, so a
     * removal never leads to two series of the same name.
     *
     * @param  list<SeriesDefinition>  $definitions
     */
    private function defaultLabel(array $definitions): string
    {
        $placeholder = '__N__';
        $pattern = '/^'.str_replace(preg_quote($placeholder, '/'), '(\d+)', preg_quote(__('comparisons.series.default_label', ['n' => $placeholder]), '/')).'$/u';
        $highest = 0;

        foreach ($definitions as $definition) {
            if (preg_match($pattern, $definition->label, $match) === 1) {
                $highest = max($highest, (int) $match[1]);
            }
        }

        return __('comparisons.series.default_label', ['n' => $highest + 1]);
    }

    /**
     * Every line of every series, flat and in series order. `color` is the
     * series' colour (table, bars, pies: lines of one series are told apart by
     * their label); `lineColor` is what the curve draws: the series' colour
     * when it has one line, otherwise one palette hue per line, since lines
     * of one colour could not be told apart there.
     *
     * @param  list<SeriesResult>  $results
     * @param  list<SeriesColor>  $colors  one per series
     * @return list<array{label: string, short: string, color: SeriesColor, lineColor: SeriesColor, series: int, line: SeriesLine}>
     */
    private function displayLines(array $results, array $colors): array
    {
        $lines = [];

        foreach ($results as $seriesIndex => $result) {
            foreach ($result->lines as $line) {
                $single = $line->seat === null || $line->key === '';
                $lines[] = [
                    'label' => $single ? $result->label : __('comparisons.line_with_seat', ['label' => $result->label, 'seat' => $line->seat]),
                    'short' => $single ? $result->label : __('comparisons.results.seat', ['seat' => $line->seat]),
                    'color' => $colors[$seriesIndex],
                    'lineColor' => count($result->lines) === 1
                        ? $colors[$seriesIndex]
                        : SeriesColor::of(SeriesColor::HUES[count($lines) % count(SeriesColor::HUES)]),
                    'series' => $seriesIndex,
                    'line' => $line,
                ];
            }
        }

        return $lines;
    }
}
