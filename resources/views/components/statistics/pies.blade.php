@use('App\Discussion\Support\LocaleNumber')

@props([
    'pies',       {{-- ResultCharts::pies() --}}
    'equalShare', {{-- 1/n, or null when the group sizes differ --}}
])

{{--
    One pie per series, side by side, drawn like the project statistics
    flyout. Four to a row, so a pipeline's status and control pies sit next
    to each other. Flux paints every sector in the -500 of the datum's
    `color`; app.css repaints it in the series' own shade (`--pie-fill`) and
    tells the slices apart by tints from each sector's data-key (`tint-N`,
    `others` in zinc), inside [data-pie-tints].
--}}
<div {{ $attributes->class('space-y-4') }}>
    <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($pies as $pie)
            <section class="flex min-w-0 flex-col" data-pie-tints style="--pie-fill: var(--color-{{ $pie['color'] }})" wire:key="pie-{{ $loop->index }}-{{ md5(json_encode($pie['slices'])) }}">
                @if ($pie['mixed'])
                    {{-- Mixed group sizes: per-seat means would add up to more than a whole. --}}
                    <div class="mx-auto flex aspect-square w-full max-w-48 items-center justify-center rounded-full border border-dashed border-zinc-300 p-6 text-center dark:border-zinc-600">
                        <flux:text size="sm">{{ __('comparisons.results.pie_mixed_sizes') }}</flux:text>
                    </div>
                @else
                    <flux:chart :value="$pie['slices']" class="mx-auto aspect-square w-full max-w-48">
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
                @endif

                {{-- Two lines, so four to a row leave the series name readable. --}}
                <div class="mt-3 min-w-0 text-center text-sm" title="{{ $pie['label'] }}">
                    <div class="flex min-w-0 items-center justify-center gap-2">
                        <span class="size-2.5 shrink-0 rounded-full" style="background-color: var(--color-{{ $pie['color'] }})"></span>
                        <span class="truncate font-medium text-zinc-800 dark:text-white">{{ $pie['label'] }}</span>
                    </div>
                    <div class="tabular-nums text-zinc-500 dark:text-zinc-400">
                        @if ($pie['mean'] !== null)
                            <span class="font-medium text-zinc-800 dark:text-white">{{ $pie['mean'] }}</span> ·
                        @endif
                        n = {{ $pie['n'] }}
                    </div>
                </div>
            </section>
        @endforeach
    </div>

    @if ($equalShare !== null)
        <flux:text size="sm">{{ __('comparisons.results.pie_equal_share', ['share' => LocaleNumber::percent($equalShare, 0)]) }}</flux:text>
    @endif
</div>
