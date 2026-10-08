<?php

namespace App\Livewire\Statistics;

use App\Discussion\Support\LocaleNumber;
use App\Discussion\Values\Metric;
use App\Discussion\Values\SeriesColor;
use App\Discussion\Values\SeriesLine;
use App\Discussion\Values\SeriesResult;

/**
 * What the four displays of a comparison draw, prepared from the computed
 * series: bars grouped by series, one pie per series, the curve rows, and the
 * equal-share mark. It computes no statistic itself; every number is a
 * SeriesLine's mean, min or max.
 *
 * @phpstan-type Line array{label: string, short: string, color: SeriesColor, lineColor: SeriesColor, series: int, line: SeriesLine}
 */
final class ResultCharts
{
    /**
     * @param  list<Line>  $lines  every line of every series, in series order
     * @param  list<SeriesResult>  $results
     */
    public function __construct(
        private readonly array $lines,
        private readonly array $results,
        private readonly Metric $metric,
    ) {}

    /**
     * 1/n when every counted run of every series had the same n agents and the
     * measure is a share; null otherwise, since mixed group sizes have no one
     * equal share.
     */
    public function equalShare(): ?float
    {
        if (! $this->metric->isShare()) {
            return null;
        }

        $sizes = array_values(array_unique(array_merge([], ...array_map(fn (SeriesResult $r) => $r->groupSizes, $this->results))));

        return count($sizes) === 1 && $sizes[0] > 0 ? 1 / $sizes[0] : null;
    }

    /** What the full bar width stands for: 100 % for shares, else the largest value shown. */
    public function barScale(): float
    {
        return $this->metric->isShare() ? 1.0 : max([1.0, ...array_map(fn (array $entry) => $entry['line']->max(), $this->lines)]);
    }

    /**
     * Axis ticks under the bars: start, middle and end of the scale.
     *
     * @return list<array{at: float, text: string}>
     */
    public function barTicks(): array
    {
        $scale = $this->barScale();

        return array_map(fn (float $value) => [
            'at' => $value / $scale * 100,
            'text' => $this->metric->isShare() ? LocaleNumber::percent($value, 0) : $this->metric->format($value),
        ], [0.0, $scale / 2, $scale]);
    }

    /**
     * Bars grouped by series: all lines of one series share its colour and
     * are told apart by their row label ("Seat 2").
     *
     * @return list<array{label: string, color: SeriesColor, rows: list<array{label: string, mean: float, min: float, max: float, meanText: string, rangeText: string, n: int, text: string}>}>
     */
    public function barGroups(): array
    {
        $groups = [];

        foreach ($this->lines as $entry) {
            $line = $entry['line'];
            $groups[$entry['series']] ??= ['label' => $this->results[$entry['series']]->label, 'color' => $entry['color'], 'rows' => []];
            $groups[$entry['series']]['rows'][] = [
                'label' => $entry['short'],
                'mean' => $line->mean(),
                'min' => $line->min(),
                'max' => $line->max(),
                'meanText' => $this->metric->format($line->mean()),
                'rangeText' => $this->metric->formatRange($line->min(), $line->max()),
                'n' => $line->n(),
                'text' => __('comparisons.results.bar_text', [
                    'mean' => $this->metric->format($line->mean()),
                    'range' => $this->metric->formatRange($line->min(), $line->max()),
                    'n' => $line->n(),
                ]),
            ];
        }

        return array_values($groups);
    }

    /**
     * One pie per series with a line. `each` gives a slice per seat (its mean
     * share); any other reference gives the referenced agents' mean share and
     * the rest as "Others". Keys follow Flux's pie datum: `color` must be a
     * pie palette name (the hue), `id` becomes the sector's data-key, which
     * app.css reads to tint the slices of one series (`tint-N`, `others`).
     * The series' own shade reaches the sectors through `--pie-fill`.
     *
     * @return list<array{label: string, color: SeriesColor, n: int, slices: list<array{id: string, name: string, value: float, share: string, color: string}>}>
     */
    public function pies(): array
    {
        if (! $this->metric->hasPie()) {
            return [];
        }

        $pies = [];

        foreach ($this->results as $index => $result) {
            $entries = array_values(array_filter($this->lines, fn (array $entry) => $entry['series'] === $index));

            if ($entries === []) {
                continue;
            }

            $color = $entries[0]['color'];
            $perSeat = $entries[0]['line']->key !== '';
            $slices = $perSeat
                ? array_map(fn (array $entry, int $i) => $this->slice("tint-{$i}", $entry['short'], $entry['line']->mean(), $color->hue), $entries, array_keys($entries))
                : [
                    $this->slice('tint-0', $result->label, $entries[0]['line']->mean(), $color->hue),
                    $this->slice('others', __('comparisons.results.others'), max(0.0, 1 - $entries[0]['line']->mean()), $color->hue),
                ];

            $pies[] = [
                'label' => $result->label,
                'color' => $color,
                'n' => max(array_map(fn (array $entry) => $entry['line']->n(), $entries)),
                'slices' => $slices,
            ];
        }

        return $pies;
    }

    /** @return array{id: string, name: string, value: float, share: string, color: string} */
    private function slice(string $id, string $name, float $share, string $color): array
    {
        return ['id' => $id, 'name' => $name, 'value' => round($share * 100, 2), 'share' => $this->metric->format($share), 'color' => $color];
    }

    /**
     * One row per spoken turn for flux:chart: `l{i}` is line i's mean share in
     * percent, `t{i}` the same value written as the table writes it (the
     * tooltip shows it verbatim, independent of the browser's locale), `n{i}`
     * how many runs reached that turn. A line that ends early has no `l{i}` in
     * later rows, which the chart draws as its end; its tooltip shows a dash.
     *
     * @return list<array<string, int|float|string>>
     */
    public function trajectoryRows(): array
    {
        $rows = [];

        foreach ($this->lines as $index => $entry) {
            foreach ($entry['line']->curve as $point) {
                $rows[$point->turn]['turn'] = $point->turn;
                $rows[$point->turn]['label'] = __('statistics.trajectory.turn', ['turn' => $point->turn]);
                $rows[$point->turn]["l{$index}"] = round($point->mean * 100, 1);
                $rows[$point->turn]["t{$index}"] = $this->metric->format($point->mean);
                $rows[$point->turn]["n{$index}"] = $point->n;
            }
        }

        ksort($rows);

        $blank = [];
        foreach (array_keys($this->lines) as $index) {
            $blank["t{$index}"] = '—';
            $blank["n{$index}"] = 0;
        }

        return array_values(array_map(fn (array $row) => $row + $blank, $rows));
    }
}
