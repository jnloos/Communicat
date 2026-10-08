@props([
    'bars',  {{-- list<array{label, color, mean, min, max, text}> --}}
    'scale', {{-- the value the full width stands for --}}
])

{{--
    Mean as a bar, min to max as a whisker across it. flux:chart cannot draw
    this: its bars always start at zero and take their colour from the field,
    not from the row (see the plan, open point 2).
--}}
@php($at = fn (float $value) => $scale > 0 ? max(0, min(100, $value / $scale * 100)) : 0)

<div {{ $attributes->class('space-y-3') }}>
    @foreach ($bars as $bar)
        <div class="grid grid-cols-1 gap-1 sm:grid-cols-[minmax(0,12rem)_1fr] sm:items-center sm:gap-3" wire:key="bar-{{ $loop->index }}">
            <span class="flex min-w-0 items-center gap-2 text-sm">
                <span class="size-2.5 shrink-0 rounded-full" style="background-color: var(--color-{{ $bar['color'] }}-500)"></span>
                <span class="truncate">{{ $bar['label'] }}</span>
            </span>

            <div>
                <div class="relative h-6 rounded bg-zinc-100 dark:bg-zinc-800" role="img" aria-label="{{ $bar['label'] }}: {{ $bar['text'] }}">
                    <div class="absolute inset-y-1 left-0 rounded-sm opacity-80"
                        style="width: {{ $at($bar['mean']) }}%; background-color: var(--color-{{ $bar['color'] }}-500)"></div>
                    <div class="absolute top-1/2 h-0.5 -translate-y-1/2 bg-zinc-800 dark:bg-zinc-100"
                        style="left: {{ $at($bar['min']) }}%; width: {{ $at($bar['max']) - $at($bar['min']) }}%"></div>
                    <div class="absolute inset-y-1 w-0.5 bg-zinc-800 dark:bg-zinc-100" style="left: {{ $at($bar['min']) }}%"></div>
                    <div class="absolute inset-y-1 w-0.5 -translate-x-full bg-zinc-800 dark:bg-zinc-100" style="left: {{ $at($bar['max']) }}%"></div>
                </div>
                <div class="mt-1 font-mono text-xs text-zinc-500 dark:text-zinc-400">{{ $bar['text'] }}</div>
            </div>
        </div>
    @endforeach
</div>
