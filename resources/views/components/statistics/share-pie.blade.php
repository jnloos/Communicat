@use('App\Discussion\Support\LocaleNumber')

@props([
    'sectors', {{-- rows from ShareChartData: name, value, share, color --}}
    'label',   {{-- the measure, e.g. "Turn share" --}}
    'unit',    {{-- what `value` counts, for the tooltip --}}
    'gini',
    'giniTitle', {{-- "Gini 0.18 (0 = equal, max 0.75 with 4 people)" --}}
    'legend',  {{-- one row per participant: name, share, color --}}
    'shareLabel', {{-- the share's name in the tooltip: the pie's own measure --}}
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
                stroke-width="1"
                class="origin-center cursor-pointer transition-[opacity,transform] duration-150 data-inactive:opacity-35 data-active:scale-105"
            />
        </flux:chart.svg>

        {{-- Flux hides the tooltip by opacity only and leaves it in place; without this it swallows clicks on whatever it last covered. --}}
        <flux:chart.tooltip class="pointer-events-none">
            <flux:chart.tooltip.heading field="name" />
            <flux:chart.tooltip.value field="value" :label="$unit">
                <flux:chart.tooltip.indicator />
            </flux:chart.tooltip.value>
            <flux:chart.tooltip.value field="share" :label="$shareLabel" />
        </flux:chart.tooltip>
    </flux:chart>

    {{-- Identity must not rest on colour or hover alone: every participant, with its share. --}}
    <ul class="mt-3 space-y-1" data-pie-legend>
        @foreach ($legend as $row)
            <li class="flex items-center gap-2 text-sm">
                <span class="size-2.5 shrink-0 rounded-full" style="background-color: var(--color-{{ $row['color'] }}-500)"></span>
                <span class="min-w-0 flex-1 truncate text-zinc-800 dark:text-zinc-200">{{ $row['name'] }}</span>
                <span class="font-mono text-zinc-700 dark:text-zinc-300">{{ $row['share'] }}</span>
            </li>
        @endforeach
    </ul>

    {{-- The coefficient means nothing without its ceiling: with
         four agents the maximum is 0.75, not 1. --}}
    <div class="mt-4 flex items-baseline justify-between border-t border-zinc-200 pt-3 dark:border-zinc-700">
        <span class="text-xs uppercase tracking-wide text-zinc-600 dark:text-zinc-400">{{ __('statistics.gini') }}</span>
        <flux:tooltip :content="$giniTitle">
            <span class="cursor-help font-mono text-sm text-zinc-700 dark:text-zinc-300" tabindex="0" data-gini :aria-label="$giniTitle">
                {{ LocaleNumber::decimal($gini, 2) }}
                <span class="text-zinc-600 dark:text-zinc-400">/ {{ LocaleNumber::decimal($ceiling, 2) }}</span>
            </span>
        </flux:tooltip>
    </div>
</flux:card>
