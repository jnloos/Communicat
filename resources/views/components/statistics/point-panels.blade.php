@use('App\Livewire\Statistics\PanelGrid')

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
    With two splits the panels form a grid: rows are the first dimension's values, columns the second's.
--}}
@php($scale = $metric->isShare() ? 1.0 : max($result->maxValue() * 1.1, 0.0001))

@if ($result->columns === [])
    <div {{ $attributes->class('grid gap-4 sm:grid-cols-2 xl:grid-cols-3') }}>
        @foreach ($result->panels as $panel)
            <x-statistics.point-card :result="$result" :metric="$metric" :panel="$panel" :heading="$panel->label" :scale="$scale" />
        @endforeach
    </div>
@else
    {{-- Two splits: a row per value of the first, a column per value of the second; a dashed cell where no run has the combination. One column on narrow screens. --}}
    <div {{ $attributes->class('space-y-4') }} style="--columns: {{ count($result->columns) }}">
        @foreach (PanelGrid::rows($result) as $row)
            <section wire:key="points-row-{{ $row['key'] }}">
                <flux:heading size="sm" class="mb-2">{{ $row['label'] }}</flux:heading>
                <div class="grid gap-4 sm:grid-cols-[repeat(var(--columns),minmax(0,1fr))]">
                    @foreach ($row['cells'] as $panel)
                        @if ($panel === null)
                            <div class="hidden rounded-lg border border-dashed border-zinc-200 sm:block dark:border-zinc-700" aria-hidden="true"></div>
                        @else
                            <x-statistics.point-card :result="$result" :metric="$metric" :panel="$panel" :heading="$panel->columnLabel" :scale="$scale" />
                        @endif
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@endif
