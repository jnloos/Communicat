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
    combination, one column on narrow screens. With more than three columns a
    row becomes a Flux carousel (arrows, swipe, snap) instead of a grid of
    squeezed cards. A card in the grid is headed by
    its column alone, since the row heading names the rest.

    Tailwind only generates classes it finds written out, hence the class
    lists per breakpoint instead of a class built from it.
--}}
@php
    [$flow, $columns, $empty] = match ($breakpoint) {
        'lg' => ['grid gap-4 lg:grid-cols-2', 'grid gap-4 lg:grid-cols-[repeat(var(--columns),minmax(0,1fr))]', 'hidden rounded-lg border border-dashed border-zinc-200 lg:block dark:border-zinc-700'],
        default => ['grid gap-4 sm:grid-cols-2 xl:grid-cols-3', 'grid gap-4 sm:grid-cols-[repeat(var(--columns),minmax(0,1fr))]', 'hidden rounded-lg border border-dashed border-zinc-200 sm:block dark:border-zinc-700'],
    };
    // Slide widths for a row with more cards than fit: three side by side on wide screens (two for the
    // wide cards), one and a bit on narrow ones, so the next card peeks out and the row reads as scrollable.
    $slide = match ($breakpoint) {
        'lg' => 'w-[85%] lg:w-[calc((100%-1rem)/2)]',
        default => 'w-[85%] sm:w-[calc((100%-1rem)/2)] xl:w-[calc((100%-2rem)/3)]',
    };
    $carouselAbove = 3; // more cards than this in one row: a Flux carousel instead of a squeezed grid
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
                @php $carousel = count($result->columns) > $carouselAbove; @endphp
                {{-- The arrows sit in the row heading, beside the row's name, so they never cover a chart. --}}
                <div class="mb-2 flex items-center justify-between gap-3">
                    <flux:heading size="sm">{{ $row['label'] }}</flux:heading>
                    @if ($carousel)
                        <flux:carousel.controls :name="$keyPrefix.'-'.$row['key']" />
                    @endif
                </div>
                @if ($carousel)
                    <flux:carousel :name="$keyPrefix.'-'.$row['key']" :arrows="false" :aria-label="$row['label']" data-panel-carousel>
                        @foreach ($row['cells'] as $panel)
                            <flux:carousel.slide class="{{ $slide }}">
                                @if ($panel === null)
                                    <div class="h-full min-h-32 rounded-lg border border-dashed border-zinc-200 dark:border-zinc-700" aria-hidden="true"></div>
                                @else
                                    <x-dynamic-component :component="$card" :result="$result" :panel="$panel" :heading="$panel->columnLabel" :attributes="$props" />
                                @endif
                            </flux:carousel.slide>
                        @endforeach
                    </flux:carousel>
                @else
                    <div class="{{ $columns }}">
                        @foreach ($row['cells'] as $panel)
                            @if ($panel === null)
                                <div class="{{ $empty }}" aria-hidden="true"></div>
                            @else
                                <x-dynamic-component :component="$card" :result="$result" :panel="$panel" :heading="$panel->columnLabel" :attributes="$props" />
                            @endif
                        @endforeach
                    </div>
                @endif
            </section>
        @endforeach
    </div>
@endif
