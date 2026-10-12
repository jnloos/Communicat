@use('App\Livewire\Statistics\GroupColor')

@props([
    'result', {{-- QuestionResult --}}
    'metric', {{-- Metric --}}
])

{{--
    Small multiples: a panel per value of the split, a row per group. Every
    run is a dot, the mean a bar across the track, the fair share a dashed
    mark. All panels share one scale, so they compare at a glance.
--}}
@php($scale = $metric->isShare() ? 1.0 : max($result->maxValue() * 1.1, 0.0001))
@php($at = fn (float $value) => max(0, min(100, $value / $scale * 100)))

<div {{ $attributes->class('grid gap-4 sm:grid-cols-2 xl:grid-cols-3') }}>
    @foreach ($result->panels as $panel)
        <div class="rounded-lg border border-zinc-200 p-3 dark:border-white/10" wire:key="points-{{ $panel->key }}">
            @if ($panel->label !== '')
                <flux:heading size="sm">{{ $panel->label }}</flux:heading>
            @endif

            <div class="mt-2 space-y-3">
                @foreach ($panel->groups as $group)
                    @php($color = GroupColor::of($result, $group))

                    <div wire:key="points-{{ $panel->key }}-{{ $group->key }}">
                        <div class="flex items-baseline justify-between gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                            <span class="truncate">{{ $group->label !== '' ? $group->label : __('questions.result.all') }}</span>
                            <span class="shrink-0 tabular-nums">
                                <span class="font-medium text-zinc-800 dark:text-white">{{ $metric->format($group->mean()) }}</span>,
                                n = {{ $group->n() }}
                            </span>
                        </div>

                        <div class="relative mt-1 h-5 rounded bg-zinc-100 dark:bg-white/10" role="img"
                            aria-label="{{ $group->label }}: {{ __('questions.result.mean') }} {{ $metric->format($group->mean()) }}, n = {{ $group->n() }}">
                            @if ($group->fairShare() !== null)
                                <span class="absolute inset-y-0 border-s border-dashed border-zinc-500 dark:border-zinc-300" style="left: {{ $at($group->fairShare()) }}%"></span>
                            @endif
                            @foreach ($group->runs as $run)
                                <span class="absolute top-1/2 size-2 -translate-x-1/2 -translate-y-1/2 rounded-full opacity-80"
                                    style="left: {{ $at($run->value) }}%; background-color: var(--color-{{ $color }})"
                                    title="{{ $run->title }}: {{ $metric->format($run->value) }}"></span>
                            @endforeach
                            <span class="absolute inset-y-0.5 w-0.5 -translate-x-1/2 bg-zinc-900 dark:bg-white" style="left: {{ $at($group->mean()) }}%"></span>
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($panel->delta() !== null)
                <flux:text size="sm" class="mt-3 tabular-nums">
                    {{ __('questions.result.delta', ['to' => $panel->groups[1]->label, 'from' => $panel->groups[0]->label, 'delta' => $metric->formatDelta($panel->delta())]) }}
                </flux:text>
            @endif
        </div>
    @endforeach
</div>
