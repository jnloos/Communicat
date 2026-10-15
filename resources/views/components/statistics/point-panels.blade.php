@props([
    'result', {{-- QuestionResult --}}
    'metric', {{-- Metric --}}
])

{{--
    Small multiples: a panel per value of the split, a row per group. Every
    run is a dot, the mean a bar across the track, the spread a pale band of
    one standard deviation either side of the mean (from two runs on), the
    fair share a dashed mark. Dots, band and fair share have hover cards; the
    band's card names the mean as well. All panels share one scale, so they compare at a glance.
    With two splits the panels form a grid (x-statistics.panel-grid).
--}}
@php($scale = $metric->isShare() ? 1.0 : max($result->maxValue() * 1.1, 0.0001))

<x-statistics.panel-grid :result="$result" card="statistics.point-card" :card-props="['metric' => $metric, 'scale' => $scale]" key-prefix="points" {{ $attributes }} />
