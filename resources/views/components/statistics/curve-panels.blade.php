@use('App\Livewire\Statistics\PanelGrid')

@props([
    'result', {{-- QuestionResult --}}
])

{{-- A chart per panel, a line per group: the mean share after each turn over the runs that reached it, and a neutral dashed line at its fair share, as in the point strip; the tooltip names whose it is. With two splits the charts form a grid: rows are the first dimension's values, columns the second's. --}}
@if ($result->columns === [])
    <div {{ $attributes->class('grid gap-4 lg:grid-cols-2') }}>
        @foreach ($result->panels as $panel)
            <x-statistics.curve-card :result="$result" :panel="$panel" :heading="$panel->label" />
        @endforeach
    </div>
@else
    {{-- Two splits: a row per value of the first, a column per value of the second; a dashed cell where no run has the combination. One column on narrow screens. --}}
    <div {{ $attributes->class('space-y-4') }} style="--columns: {{ count($result->columns) }}">
        @foreach (PanelGrid::rows($result) as $row)
            <section wire:key="curves-row-{{ $row['key'] }}">
                <flux:heading size="sm" class="mb-2">{{ $row['label'] }}</flux:heading>
                <div class="grid gap-4 lg:grid-cols-[repeat(var(--columns),minmax(0,1fr))]">
                    @foreach ($row['cells'] as $panel)
                        @if ($panel === null)
                            <div class="hidden rounded-lg border border-dashed border-zinc-200 lg:block dark:border-zinc-700" aria-hidden="true"></div>
                        @else
                            <x-statistics.curve-card :result="$result" :panel="$panel" :heading="$panel->columnLabel" />
                        @endif
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@endif
