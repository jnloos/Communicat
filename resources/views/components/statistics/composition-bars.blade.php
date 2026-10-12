@use('App\Livewire\Statistics\GroupColor')

@props([
    'result', {{-- QuestionResult --}}
    'metric', {{-- Metric --}}
])

{{--
    One 100 % bar per panel, a segment per group: how the floor divides. Only
    offered when the groups add up to everyone (Question::supports), so the
    segments always fill the bar. Bars and not pies: several compositions are
    compared side by side, and lengths on one axis read better than angles.
--}}
<div {{ $attributes->class('space-y-3') }}>
    @foreach ($result->panels as $panel)
        <div class="grid grid-cols-1 gap-1 sm:grid-cols-[minmax(0,10rem)_1fr] sm:items-center sm:gap-4" wire:key="composition-{{ $panel->key }}">
            <span class="truncate text-sm text-zinc-600 dark:text-zinc-300">{{ $panel->label !== '' ? $panel->label : __('questions.result.all') }}</span>

            <div class="flex h-7 overflow-hidden rounded" role="img"
                aria-label="{{ collect($panel->groups)->map(fn ($group) => $group->label.': '.$metric->format($group->mean()))->implode(', ') }}">
                @foreach ($panel->groups as $group)
                    <div class="flex min-w-0 items-center justify-center overflow-hidden text-xs font-medium text-white"
                        style="width: {{ $group->mean() * 100 }}%; background-color: var(--color-{{ GroupColor::of($result, $group) }})"
                        title="{{ $group->label }}: {{ $metric->format($group->mean()) }}">
                        <span class="truncate px-1">{{ $metric->format($group->mean()) }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
