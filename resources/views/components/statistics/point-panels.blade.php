@use('App\Livewire\Statistics\GroupColor')

@props([
    'result', {{-- QuestionResult --}}
    'metric', {{-- Metric --}}
])

{{--
    Small multiples: a panel per value of the split, a row per group. Every
    run is a dot, the mean a bar across the track, the fair share a dashed
    mark, each with a hover card. All panels share one scale, so they compare at a glance.
--}}
@php($scale = $metric->isShare() ? 1.0 : max($result->maxValue() * 1.1, 0.0001))
@php($at = fn (float $value) => max(0, min(100, $value / $scale * 100)))

<div {{ $attributes->class('grid gap-4 sm:grid-cols-2 xl:grid-cols-3') }}>
    @foreach ($result->panels as $panel)
        <flux:card size="sm" class="p-3" wire:key="points-{{ $panel->key }}">
            @if ($panel->label !== '')
                <flux:heading size="sm">{{ $panel->label }}</flux:heading>
            @endif

            <div class="mt-2 space-y-3">
                @foreach ($panel->groups as $group)
                    @php($color = GroupColor::of($result, $group))
                    @php($groupLabel = $group->label !== '' ? $group->label : __('questions.result.all'))

                    <div wire:key="points-{{ $panel->key }}-{{ $group->key }}">
                        <div class="flex items-baseline justify-between gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                            <span class="truncate">{{ $groupLabel }}</span>
                            <span class="shrink-0 tabular-nums">
                                <span class="font-medium text-zinc-800 dark:text-white">{{ $metric->format($group->mean()) }}</span>,
                                n = {{ $group->n() }}
                            </span>
                        </div>

                        <div class="relative mt-1 h-5 rounded bg-zinc-100 dark:bg-white/10" role="img"
                            aria-label="{{ $groupLabel }}: {{ __('questions.result.mean') }} {{ $metric->format($group->mean()) }}, n = {{ $group->n() }}">
                            {{-- Fair share and mean come before the dots, so a dot on top of either keeps the hover; each line has an 8 px hover target. --}}
                            @if ($group->fairShare() !== null)
                                <x-statistics.chart-tip :heading="$groupLabel" :rows="[['label' => __('questions.result.fair_share'), 'value' => $metric->format($group->fairShare()), 'color' => null]]"
                                    class="absolute inset-y-0 flex w-2 -translate-x-1/2 justify-center" style="left: {{ $at($group->fairShare()) }}%">
                                    <span class="h-full border-s border-dashed border-zinc-500 dark:border-zinc-300"></span>
                                </x-statistics.chart-tip>
                            @endif
                            <x-statistics.chart-tip :heading="$groupLabel" :rows="[['label' => __('questions.result.mean'), 'value' => $metric->format($group->mean()), 'color' => $color], ['label' => 'n', 'value' => $group->n(), 'color' => null]]"
                                class="absolute inset-y-0.5 flex w-2 -translate-x-1/2 justify-center" style="left: {{ $at($group->mean()) }}%">
                                <span class="h-full w-0.5 bg-zinc-900 dark:bg-white"></span>
                            </x-statistics.chart-tip>
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
    @endforeach
</div>
