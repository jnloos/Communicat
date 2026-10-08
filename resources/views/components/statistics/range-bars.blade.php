@use('App\Discussion\Support\LocaleNumber')

@props([
    'groups',     {{-- ResultCharts::barGroups() --}}
    'scale',      {{-- the value the full width stands for --}}
    'ticks',      {{-- ResultCharts::barTicks() --}}
    'equalShare', {{-- 1/n, or null when the group sizes differ or the measure is no share --}}
])

{{--
    Mean as a bar on a visible track, min to max as a whisker below it, the
    figures in a right-hand column. One colour per series; its lines are told
    apart by their label. flux:chart cannot draw this: its bars always start at
    zero and take their colour from the field, not from the row.
--}}
@php($at = fn (float $value) => $scale > 0 ? max(0, min(100, $value / $scale * 100)) : 0)
@php($grid = 'grid grid-cols-1 gap-1 sm:grid-cols-[minmax(0,10rem)_1fr_9rem] sm:items-center sm:gap-x-4')

<div {{ $attributes->class('space-y-5') }}>
    @foreach ($groups as $group)
        {{-- A series with one line needs no heading: the row carries its name. --}}
        @php($single = count($group['rows']) === 1 && $group['rows'][0]['label'] === $group['label'])

        <div class="space-y-2" wire:key="bar-group-{{ $loop->index }}">
            @if (! $single)
                <div class="flex items-center gap-2 text-sm font-medium text-zinc-800 dark:text-white">
                    <span class="size-2.5 shrink-0 rounded-full" style="background-color: var(--color-{{ $group['color'] }})"></span>
                    <span class="truncate">{{ $group['label'] }}</span>
                </div>
            @endif

            @foreach ($group['rows'] as $row)
                <div class="{{ $grid }}" wire:key="bar-{{ $loop->parent->index }}-{{ $loop->index }}">
                    <span class="flex min-w-0 items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                        @if ($single)
                            <span class="size-2.5 shrink-0 rounded-full" style="background-color: var(--color-{{ $group['color'] }})"></span>
                        @endif
                        <span class="truncate">{{ $row['label'] }}</span>
                    </span>

                    <div role="img" aria-label="{{ $row['label'] }}: {{ $row['text'] }}">
                        <div class="relative h-5 overflow-hidden rounded bg-zinc-100 dark:bg-white/10">
                            <div class="absolute inset-y-0 left-0 rounded-e" style="width: {{ $at($row['mean']) }}%; background-color: var(--color-{{ $group['color'] }})"></div>
                            @if ($equalShare !== null)
                                <div class="absolute inset-y-0 border-s border-dashed border-zinc-500 dark:border-zinc-300" style="left: {{ $at($equalShare) }}%"></div>
                            @endif
                        </div>
                        {{-- Whisker: min to max, under the bar so the bar's end stays readable. --}}
                        <div class="relative mt-1 h-2">
                            <div class="absolute top-1/2 h-px -translate-y-1/2 bg-zinc-500 dark:bg-zinc-300" style="left: {{ $at($row['min']) }}%; width: {{ $at($row['max']) - $at($row['min']) }}%"></div>
                            <div class="absolute inset-y-0 w-px bg-zinc-500 dark:bg-zinc-300" style="left: {{ $at($row['min']) }}%"></div>
                            <div class="absolute inset-y-0 w-px -translate-x-full bg-zinc-500 dark:bg-zinc-300" style="left: {{ $at($row['max']) }}%"></div>
                        </div>
                    </div>

                    <div class="text-sm tabular-nums sm:text-right">
                        <span class="font-medium text-zinc-800 dark:text-white">{{ $row['meanText'] }}</span>
                        <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $row['rangeText'] }} · n = {{ $row['n'] }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    @endforeach

    {{-- Axis under the last track. --}}
    <div class="{{ $grid }} max-sm:hidden" aria-hidden="true">
        <span></span>
        <div class="relative h-4 text-xs tabular-nums text-zinc-500 dark:text-zinc-400">
            @foreach ($ticks as $tick)
                <span @class([
                    'absolute top-0 whitespace-nowrap',
                    '-translate-x-1/2' => ! $loop->first && ! $loop->last,
                    '-translate-x-full' => $loop->last,
                ]) style="left: {{ $tick['at'] }}%">{{ $tick['text'] }}</span>
            @endforeach
        </div>
        <span></span>
    </div>

    @if ($equalShare !== null)
        <flux:text size="sm" class="flex items-center gap-2">
            <span class="inline-block h-3 border-s border-dashed border-zinc-500 dark:border-zinc-300"></span>
            {{ __('comparisons.results.equal_share', ['share' => LocaleNumber::percent($equalShare, 0)]) }}
        </flux:text>
    @endif
</div>
