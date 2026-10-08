<?php

namespace App\Livewire\Statistics;

use App\Discussion\Metrics\SeriesProjects;
use App\Discussion\Metrics\SeriesStatistics;
use App\Discussion\Values\ComparisonDisplay;
use App\Discussion\Values\ComparisonView;
use App\Discussion\Values\SeriesColor;
use App\Discussion\Values\SeriesDefinition;
use App\Discussion\Values\SeriesResult;
use App\Models\TagValue;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * A comparison computed for one user: which runs each series matches, their
 * statistics, the colours, the flat list of lines every display draws, the
 * one-line summaries and the notices about vanished tags. It is everything the
 * editor shows below its form, assembled without Livewire.
 *
 * @phpstan-import-type Line from ResultCharts
 */
final readonly class ComparisonResult
{
    /**
     * @param  list<SeriesDefinition>  $series  carrying their display labels
     * @param  list<Collection>  $matches  the readable projects per series
     * @param  list<SeriesResult>  $results
     * @param  list<SeriesColor>  $colors  one per series
     * @param  list<Line>  $lines
     * @param  list<string>  $summaries  one per series
     * @param  list<string>  $emptiedSeries  labels of series whose filter can match nothing any more
     * @param  list<string>  $unresolvedSeries  labels of series naming tags this database does not know
     */
    private function __construct(
        public ComparisonView $view,
        public array $series,
        public array $matches,
        public array $results,
        public array $colors,
        public array $lines,
        public array $summaries,
        public array $emptiedSeries,
        public array $unresolvedSeries,
    ) {}

    /**
     * @param  list<SeriesDefinition>  $definitions  as stored, labels may be empty
     * @param  array<string, string>  $pipelines  stored name => label, for the summaries
     * @param  array<string, string>  $models  key => label, for the summaries
     */
    public static function compute(User $user, array $definitions, ComparisonView $view, array $pipelines, array $models): self
    {
        $labelled = SeriesLabels::withDefaults($definitions);
        $matches = array_map(fn (SeriesDefinition $d) => app(SeriesProjects::class)->for($user, $d), $labelled);
        $results = array_map(
            fn (SeriesDefinition $d, Collection $projects) => app(SeriesStatistics::class)->compute($d, $view, $projects),
            $labelled,
            $matches,
        );
        $colors = SeriesColor::assign(array_map(fn (SeriesDefinition $d) => $d->color, $labelled));

        return new self(
            view: $view,
            series: $labelled,
            matches: $matches,
            results: $results,
            colors: $colors,
            lines: self::displayLines($results, $colors),
            summaries: self::summaries($labelled, $matches, $pipelines, $models),
            emptiedSeries: self::emptiedSeries($labelled),
            unresolvedSeries: array_values(array_map(
                fn (SeriesDefinition $d) => $d->label,
                array_filter($labelled, fn (SeriesDefinition $d) => $d->hasUnresolvedTags()),
            )),
        );
    }

    public function charts(): ResultCharts
    {
        return new ResultCharts($this->lines, $this->results, $this->view->metric);
    }

    /**
     * What the shown display draws, and nothing for the others.
     *
     * @return array<string, mixed>
     */
    public function chartData(): array
    {
        $charts = $this->charts();
        $none = ['barGroups' => null, 'barScale' => null, 'barTicks' => null, 'equalShare' => null, 'pies' => null, 'trajectoryRows' => null];

        return match ($this->view->display) {
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

    /** True when the shown display draws two things in one colour: the curve colours each line, every other display each series. */
    public function paletteRepeats(): bool
    {
        $drawn = array_map('strval', $this->view->display === ComparisonDisplay::Trajectory ? array_column($this->lines, 'lineColor') : $this->colors);

        return count(array_unique($drawn)) < count($drawn);
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
     * @return list<Line>
     */
    private static function displayLines(array $results, array $colors): array
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

    /**
     * @param  list<SeriesDefinition>  $definitions  carrying their display labels
     * @param  list<Collection>  $matches
     * @param  array<string, string>  $pipelines
     * @param  array<string, string>  $models
     * @return list<string>
     */
    private static function summaries(array $definitions, array $matches, array $pipelines, array $models): array
    {
        $ids = array_merge([], ...array_map(fn (SeriesDefinition $d) => $d->tagValueIds(), $definitions));
        $tagLabels = TagValue::with('category')->whereKey($ids)->get()->mapWithKeys(fn (TagValue $v) => [$v->id => $v->fullLabel()])->all();
        $summary = new SeriesSummary($tagLabels, $pipelines, $models);

        return array_map(fn (SeriesDefinition $d, Collection $projects) => $summary->of($d, $projects->count()), $definitions, $matches);
    }

    /**
     * Series whose project filter names a category none of whose chosen values
     * exists any more. They match no run (never all of them), and the notice
     * says which ones.
     *
     * @param  list<SeriesDefinition>  $definitions  carrying their display labels
     * @return list<string>
     */
    private static function emptiedSeries(array $definitions): array
    {
        $named = array_merge([], ...array_map(fn (SeriesDefinition $d) => $d->projectTags->valueIds(), $definitions));
        $existing = TagValue::whereKey($named)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_map(
            fn (SeriesDefinition $d) => $d->label,
            array_filter($definitions, fn (SeriesDefinition $d) => $d->projectTags->isUnsatisfiable($existing)),
        ));
    }
}
