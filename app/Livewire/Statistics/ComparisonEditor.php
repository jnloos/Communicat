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
use App\Discussion\Values\SeriesDefinition;
use App\Discussion\Values\SeriesLine;
use App\Discussion\Values\SeriesResult;
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
    /**
     * Series colours, handed out in line order. Deliberately not the seat
     * colours of the statistics flyout (blue, violet, emerald, …): a series is
     * not a participant. All eight exist as --color-*-500 in the built CSS.
     */
    public const PALETTE = ['indigo', 'orange', 'teal', 'pink', 'sky', 'red', 'purple', 'lime'];

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

        $named = array_values(array_unique(array_merge([], ...array_map(fn (SeriesDefinition $d) => $d->tagValueIds(), $definitions))));
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
        $label = __('comparisons.series.default_label', ['n' => count($this->series) + 1]);
        $this->series[] = (new SeriesDefinition($label))->toArray();
        $this->persist();
    }

    public function removeSeries(int $index): void
    {
        unset($this->series[$index]);
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
        return array_values(array_map(
            fn (mixed $series) => SeriesDefinition::fromArray(is_array($series) ? $series : []),
            $this->series,
        ));
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
        $lines = $this->displayLines($results);
        [$bars, $barScale] = $this->bars($lines, $view->metric);

        return view('livewire.statistics.comparison-editor', [
            'definitions' => $definitions,
            'matches' => $matches,
            'results' => $results,
            'lines' => $lines,
            'seriesColors' => $this->seriesColors($lines, count($definitions)),
            'bars' => $bars,
            'barScale' => $barScale,
            'trajectoryRows' => $this->trajectoryRows($lines),
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
     * A label may be stored empty; the result then shows the default name, so
     * no legend, row or tooltip renders blank.
     *
     * @param  list<SeriesDefinition>  $definitions
     * @return list<SeriesDefinition>
     */
    private function withDefaultLabels(array $definitions): array
    {
        return array_map(
            fn (SeriesDefinition $d, int $index) => $d->label === ''
                ? $d->withLabel(__('comparisons.series.default_label', ['n' => $index + 1]))
                : $d,
            $definitions,
            array_keys($definitions),
        );
    }

    /**
     * Every line of every series, flat and in series order, each with the
     * colour it keeps in the table, the bars and the curve.
     *
     * @param  list<SeriesResult>  $results
     * @return list<array{label: string, color: string, series: int, line: SeriesLine}>
     */
    private function displayLines(array $results): array
    {
        $lines = [];

        foreach ($results as $seriesIndex => $result) {
            foreach ($result->lines as $line) {
                $lines[] = [
                    'label' => $line->seat === null || $line->key === ''
                        ? $result->label
                        : __('comparisons.line_with_seat', ['label' => $result->label, 'seat' => $line->seat]),
                    'color' => self::PALETTE[count($lines) % count(self::PALETTE)],
                    'series' => $seriesIndex,
                    'line' => $line,
                ];
            }
        }

        return $lines;
    }

    /**
     * The colour of each series' first line, for the dot in the editor; a
     * series without a line yet falls back to its index.
     *
     * @return array<int, string>
     */
    private function seriesColors(array $lines, int $seriesCount): array
    {
        $colors = [];

        foreach (range(0, max(0, $seriesCount - 1)) as $index) {
            $colors[$index] = self::PALETTE[$index % count(self::PALETTE)];
        }

        foreach (array_reverse($lines) as $entry) {
            $colors[$entry['series']] = $entry['color'];
        }

        return $colors;
    }

    /**
     * One bar per line: mean as the bar, min to max as the span. Shares fill
     * against 100 %; other measures against the largest value shown.
     *
     * @return array{0: list<array{label: string, color: string, mean: float, min: float, max: float, text: string}>, 1: float}
     */
    private function bars(array $lines, Metric $metric): array
    {
        $scale = $metric->isShare() ? 1.0 : max([1.0, ...array_map(fn (array $entry) => $entry['line']->max(), $lines)]);

        $bars = array_map(fn (array $entry) => [
            'label' => $entry['label'],
            'color' => $entry['color'],
            'mean' => $entry['line']->mean(),
            'min' => $entry['line']->min(),
            'max' => $entry['line']->max(),
            'text' => __('comparisons.results.bar_text', [
                'mean' => $metric->format($entry['line']->mean()),
                'min' => $metric->format($entry['line']->min()),
                'max' => $metric->format($entry['line']->max()),
                'n' => $entry['line']->n(),
            ]),
        ], $lines);

        return [$bars, $scale];
    }

    /**
     * One row per spoken turn for flux:chart: `l{i}` is line i's mean share in
     * percent, `n{i}` how many runs reached that turn. A line that ends early
     * simply has no field in later rows, which the chart draws as its end.
     *
     * @return list<array<string, int|float|string>>
     */
    private function trajectoryRows(array $lines): array
    {
        $rows = [];

        foreach ($lines as $index => $entry) {
            foreach ($entry['line']->curve as $point) {
                $rows[$point->turn]['turn'] = $point->turn;
                $rows[$point->turn]['label'] = __('statistics.trajectory.turn', ['turn' => $point->turn]);
                $rows[$point->turn]["l{$index}"] = round($point->mean * 100, 1);
                $rows[$point->turn]["n{$index}"] = $point->n;
            }
        }

        ksort($rows);

        return array_values($rows);
    }
}
