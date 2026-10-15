@props([
    'result', {{-- QuestionResult --}}
])

{{-- A chart per panel, a line per group: the mean share after each turn over the runs that reached it, and a neutral dashed line at its fair share, as in the point strip; the tooltip names whose it is. With two splits the charts form a grid (x-statistics.panel-grid). --}}
<x-statistics.panel-grid :result="$result" card="statistics.curve-card" key-prefix="curves" breakpoint="lg" {{ $attributes }} />
