@use('App\Livewire\Statistics\PanelGrid')
@use('Illuminate\View\ComponentAttributeBag')

@props([
    'result',              {{-- QuestionResult --}}
    'card',                {{-- the card component per panel, e.g. "statistics.point-card"; it takes result, panel and heading --}}
    'cardProps' => [],     {{-- further props every card gets, e.g. metric and scale --}}
    'keyPrefix',           {{-- wire:key prefix of the grid rows --}}
    'breakpoint' => 'sm',  {{-- sm: compact cards, two to three a row; lg: wide cards, two a row --}}
])

{{--
    The panels of an answer. Split by one dimension or none, they flow as
    cards; split by two, they form a grid: a row per value of the first, a
    column per value of the second, a dashed cell where no run has the
    combination, one column on narrow screens. A card in the grid is headed by
    its column alone, since the row heading names the rest.

    Tailwind only generates classes it finds written out, hence the class
    lists per breakpoint instead of a class built from it.
--}}
@php
    [$flow, $columns, $empty] = match ($breakpoint) {
        'lg' => ['grid gap-4 lg:grid-cols-2', 'grid gap-4 lg:grid-cols-[repeat(var(--columns),minmax(0,1fr))]', 'hidden rounded-lg border border-dashed border-zinc-200 lg:block dark:border-zinc-700'],
        default => ['grid gap-4 sm:grid-cols-2 xl:grid-cols-3', 'grid gap-4 sm:grid-cols-[repeat(var(--columns),minmax(0,1fr))]', 'hidden rounded-lg border border-dashed border-zinc-200 sm:block dark:border-zinc-700'],
    };
    $props = new ComponentAttributeBag($cardProps);
@endphp

@if ($result->columns === [])
    <div {{ $attributes->class($flow) }}>
        @foreach ($result->panels as $panel)
            <x-dynamic-component :component="$card" :result="$result" :panel="$panel" :heading="$panel->label" :attributes="$props" />
        @endforeach
    </div>
@else
    <div {{ $attributes->class('space-y-4') }} style="--columns: {{ count($result->columns) }}">
        @foreach (PanelGrid::rows($result) as $row)
            <section wire:key="{{ $keyPrefix }}-row-{{ $row['key'] }}">
                <flux:heading size="sm" class="mb-2">{{ $row['label'] }}</flux:heading>
                <div class="{{ $columns }}">
                    @foreach ($row['cells'] as $panel)
                        @if ($panel === null)
                            <div class="{{ $empty }}" aria-hidden="true"></div>
                        @else
                            <x-dynamic-component :component="$card" :result="$result" :panel="$panel" :heading="$panel->columnLabel" :attributes="$props" />
                        @endif
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@endif
