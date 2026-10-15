@use('App\Livewire\Statistics\GroupColor')

@props([
    'result', {{-- QuestionResult --}}
    'metric', {{-- Metric --}}
    'panel', {{-- Panel --}}
    'heading', {{-- string: the panel's label, or in a grid only its column --}}
    'scale', {{-- float: the value at the right end of every track; shared by all panels --}}
])

@php($at = fn (float $value) => max(0, min(100, $value / $scale * 100)))

<flux:card size="sm" class="p-3" wire:key="points-{{ $panel->key }}">
    @if ($heading !== '')
        <flux:heading size="sm">{{ $heading }}</flux:heading>
    @endif

    <div class="mt-2 space-y-3">
        @foreach ($panel->groups as $group)
            @php($color = GroupColor::of($result, $group))
            @php($groupLabel = $group->displayLabel())
            @php($deviation = $group->standardDeviation())
            @php($summary = __('questions.result.mean').' '.$metric->format($group->mean()).($deviation !== null ? ', '.__('questions.result.standard_deviation').' '.$metric->format($deviation) : ''))

            <div wire:key="points-{{ $panel->key }}-{{ $group->key }}">
                <div class="flex items-baseline justify-between gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                    <span class="truncate">{{ $groupLabel }}</span>
                    <span class="shrink-0 tabular-nums">
                        <span class="font-medium text-zinc-800 dark:text-white">{{ $metric->format($group->mean()) }}</span>,
                        n = {{ $group->n() }}
                    </span>
                </div>

                <div class="relative mt-1 h-5 rounded bg-zinc-100 dark:bg-white/10" role="img"
                    aria-label="{{ $groupLabel }}: {{ $summary }}, n = {{ $group->n() }}">
                    {{-- Band, fair share and mean come before the dots, so a dot on top of any of them keeps the hover. The band's card carries the mean too, so the mean line lets the pointer through to it; the band is at least 8 px wide, even when every run has the same value. The fair-share line has an 8 px hover target of its own. --}}
                    @if ($deviation !== null)
                        @php($low = max(0.0, $group->mean() - $deviation))
                        @php($high = min($scale, $group->mean() + $deviation))
                        <x-statistics.chart-tip :heading="$groupLabel" :rows="[
                                ['label' => __('questions.result.mean'), 'value' => $metric->format($group->mean()), 'color' => $color],
                                ['label' => __('questions.result.spread'), 'value' => $metric->format($low).' – '.$metric->format($high), 'color' => null],
                                ['label' => 'n', 'value' => $group->n(), 'color' => null],
                            ]"
                            class="absolute inset-y-0 min-w-2" style="left: {{ $at($low) }}%; width: {{ $at($high) - $at($low) }}%" data-spread>
                            <span class="h-full w-full rounded-sm opacity-25" style="background-color: var(--color-{{ $color }})"></span>
                        </x-statistics.chart-tip>
                    @endif
                    @if ($group->fairShare() !== null)
                        <x-statistics.chart-tip :heading="$groupLabel" :rows="[['label' => __('questions.result.fair_share'), 'value' => $metric->format($group->fairShare()), 'color' => null]]"
                            class="absolute inset-y-0 flex w-2 -translate-x-1/2 justify-center" style="left: {{ $at($group->fairShare()) }}%">
                            <span class="h-full border-s border-dashed border-zinc-500 dark:border-zinc-300"></span>
                        </x-statistics.chart-tip>
                    @endif
                    <span class="pointer-events-none absolute inset-y-0.5 w-0.5 -translate-x-1/2 bg-zinc-900 dark:bg-white" style="left: {{ $at($group->mean()) }}%" data-mean></span>
                    @foreach ($group->runs as $run)
                        {{-- A 16 px hover target around the 8 px dot; the dot grows on hover. --}}
                        <x-statistics.chart-tip :heading="$run->title" :rows="[['label' => $groupLabel, 'value' => $metric->format($run->value), 'color' => $color]]"
                            class="group/dot absolute top-1/2 flex size-4 -translate-x-1/2 -translate-y-1/2 items-center justify-center" style="left: {{ $at($run->value) }}%">
                            <span class="size-2 rounded-full opacity-80 transition group-hover/dot:scale-150 group-hover/dot:opacity-100"
                                style="background-color: var(--color-{{ $color }})"></span>
                        </x-statistics.chart-tip>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>

    @if ($panel->delta() !== null)
        <flux:text size="sm" class="mt-3 tabular-nums">
            {{ __('questions.result.delta', ['delta' => $metric->formatDelta($panel->delta())]) }}
        </flux:text>
    @endif
</flux:card>
