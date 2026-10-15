@use('App\Discussion\Support\LocaleNumber')

@props([
    'sectors', {{-- rows from ShareChartData: name, value, share, color --}}
    'label',   {{-- the measure, e.g. "Turn share" --}}
    'unit',    {{-- what `value` counts, for the tooltip --}}
    'gini',
    'ceiling', {{-- the largest Gini this group size can reach --}}
    'total',   {{-- the line under the heading, e.g. "60 turns in total" --}}
])

<flux:card size="sm">
    <flux:heading size="sm">{{ $label }}</flux:heading>
    <flux:text size="sm" class="mt-0.5">{{ $total }}</flux:text>

    {{-- Hovering a sector: Flux marks the others data-inactive and
         hands the tooltip the sector's own colour through
         --flux-chart-color, which the indicator picks up. --}}
    <flux:chart :value="$sectors" class="mt-4 aspect-square">
        <flux:chart.svg>
            <flux:chart.pie
                field="value"
                label-field="name"
                class="origin-center cursor-pointer transition-[opacity,transform] duration-150 data-inactive:opacity-35 data-active:scale-105"
            />
        </flux:chart.svg>

        <flux:chart.tooltip>
            <flux:chart.tooltip.heading field="name" />
            <flux:chart.tooltip.value field="value" :label="$unit">
                <flux:chart.tooltip.indicator />
            </flux:chart.tooltip.value>
            <flux:chart.tooltip.value field="share" :label="__('statistics.columns.turn_share')" />
        </flux:chart.tooltip>
    </flux:chart>

    {{-- The coefficient means nothing without its ceiling: with
         four agents the maximum is 0.75, not 1. --}}
    <div class="mt-4 flex items-baseline justify-between border-t border-zinc-200 pt-3 dark:border-zinc-700">
        <span class="text-xs uppercase tracking-wide text-zinc-500">{{ __('statistics.gini') }}</span>
        <span class="font-mono text-sm text-zinc-700 dark:text-zinc-300">
            {{ LocaleNumber::decimal($gini, 2) }}
            <span class="text-zinc-400">/ {{ LocaleNumber::decimal($ceiling, 2) }}</span>
        </span>
    </div>
</flux:card>
