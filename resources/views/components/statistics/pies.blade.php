@props([
    'pies', {{-- ResultCharts::pies() --}}
])

{{--
    One pie per series, side by side, drawn like the project statistics
    flyout. Flux paints every sector in the -500 of the datum's `color`; the
    series' slices are told apart by tints that app.css derives from each
    sector's data-key (`tint-N`, `others` in zinc), inside [data-pie-tints].
--}}
<div {{ $attributes->class('grid gap-6 sm:grid-cols-2 lg:grid-cols-3') }}>
    @foreach ($pies as $pie)
        <section class="min-w-0" data-pie-tints wire:key="pie-{{ $loop->index }}-{{ md5(json_encode($pie['slices'])) }}">
            <flux:chart :value="$pie['slices']" class="mx-auto aspect-square max-w-60">
                <flux:chart.svg>
                    <flux:chart.pie
                        field="value"
                        label-field="name"
                        class="origin-center cursor-pointer transition-[opacity,transform] duration-150 data-inactive:opacity-35 data-active:scale-105"
                    />
                </flux:chart.svg>

                <flux:chart.tooltip>
                    <flux:chart.tooltip.heading field="name" />
                    <flux:chart.tooltip.value field="share" :label="__('comparisons.results.mean')">
                        <flux:chart.tooltip.indicator />
                    </flux:chart.tooltip.value>
                </flux:chart.tooltip>
            </flux:chart>

            <div class="mt-3 flex items-center justify-center gap-2 text-sm">
                <span class="size-2.5 shrink-0 rounded-full" style="background-color: var(--color-{{ $pie['color'] }}-500)"></span>
                <span class="truncate font-medium text-zinc-800 dark:text-white">{{ $pie['label'] }}</span>
                <span class="shrink-0 tabular-nums text-zinc-500 dark:text-zinc-400">n = {{ $pie['n'] }}</span>
            </div>
        </section>
    @endforeach
</div>
