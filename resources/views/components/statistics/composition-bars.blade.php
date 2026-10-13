@use('App\Livewire\Statistics\GroupColor')

@props([
    'result', {{-- QuestionResult --}}
    'metric', {{-- Metric --}}
])

{{--
    One 100 % bar per panel, a segment per group: how the floor divides. Only
    offered when the groups add up to everyone (QuestionDefinition::supports). A segment
    is Panel::shareOf(), not the group's mean over the runs that have it: a
    group a run lacks counts as 0 there, so the segments fill the bar however
    unevenly the groups occur. Bars and not pies: several compositions are
    compared side by side, and lengths on one axis read better than angles.
--}}
<div {{ $attributes->class('space-y-3') }}>
    @foreach ($result->panels as $panel)
        @php($panelLabel = $panel->label !== '' ? $panel->label : __('questions.result.all'))
        <div class="grid grid-cols-1 gap-1 sm:grid-cols-[minmax(0,10rem)_1fr] sm:items-center sm:gap-4" wire:key="composition-{{ $panel->key }}">
            <span class="truncate text-sm text-zinc-600 dark:text-zinc-300">{{ $panelLabel }}</span>

            <div class="flex h-7 overflow-hidden rounded" role="img"
                aria-label="{{ collect($panel->groups)->map(fn ($group) => $group->label.': '.$metric->format($panel->shareOf($group)))->implode(', ') }}">
                @foreach ($panel->groups as $group)
                    @php($color = GroupColor::of($result, $group))
                    <x-statistics.chart-tip :heading="$panelLabel" :rows="[['label' => $group->label, 'value' => $metric->format($panel->shareOf($group)), 'color' => $color]]"
                        class="flex min-w-0" style="width: {{ $panel->shareOf($group) * 100 }}%">
                        <div class="flex min-w-0 flex-1 items-center justify-center overflow-hidden text-xs font-medium transition hover:brightness-110 {{ GroupColor::TEXT_ON }}"
                            style="background-color: var(--color-{{ $color }})">
                            <span class="truncate px-1">{{ $metric->format($panel->shareOf($group)) }}</span>
                        </div>
                    </x-statistics.chart-tip>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
