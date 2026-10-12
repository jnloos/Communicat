@use('App\Livewire\Statistics\CurveRows')
@use('App\Livewire\Statistics\GroupColor')

@props([
    'result', {{-- QuestionResult --}}
])

{{-- A chart per panel, a line per group: the mean share after each turn over the runs that reached it, and a dashed line in the group's colour at its fair share. --}}
<div {{ $attributes->class('grid gap-4 lg:grid-cols-2') }}>
    @foreach ($result->panels as $panel)
        @php($rows = CurveRows::of($panel))

        <flux:card size="sm" class="p-3" wire:key="curve-{{ $panel->key }}">
            @if ($panel->label !== '')
                <flux:heading size="sm">{{ $panel->label }}</flux:heading>
            @endif

            @if (count($rows) < 2)
                <flux:text size="sm" class="mt-2">{{ __('questions.result.too_short') }}</flux:text>
            @else
                {{-- Keyed on the data: the chart's children are wire:ignore'd, so new rows need a fresh element. --}}
                <flux:chart wire:key="curve-{{ $panel->key }}-{{ md5(json_encode($rows)) }}" :value="$rows" class="mt-2 aspect-[2/1]">
                    <flux:chart.svg>
                        @foreach ($panel->groups as $index => $group)
                            <flux:chart.line :field="'g'.$index" curve="none" style="color: var(--color-{{ GroupColor::of($result, $group) }})" />
                            @if ($group->fairShare() !== null)
                                <flux:chart.line :field="'f'.$index" curve="none" stroke-width="1.5" stroke-dasharray="4 4" style="color: var(--color-{{ GroupColor::of($result, $group) }})" />
                            @endif
                        @endforeach

                        <flux:chart.axis axis="x" field="turn" scale="linear">
                            <flux:chart.axis.line />
                            <flux:chart.axis.tick />
                        </flux:chart.axis>
                        <flux:chart.axis axis="y" tick-start="0" tick-end="100" tick-suffix=" %">
                            <flux:chart.axis.grid />
                            <flux:chart.axis.tick />
                        </flux:chart.axis>

                        <flux:chart.cursor />
                    </flux:chart.svg>

                    <flux:chart.tooltip>
                        <flux:chart.tooltip.heading field="turn" />
                        @foreach ($panel->groups as $index => $group)
                            @php($label = $group->label !== '' ? $group->label : __('questions.result.all'))
                            <flux:chart.tooltip.value :field="'g'.$index" :label="$label" suffix=" %" />
                            <flux:chart.tooltip.value :field="'n'.$index" :label="__('questions.result.n_of', ['label' => $label])" />
                            @if ($group->fairShare() !== null)
                                <flux:chart.tooltip.value :field="'f'.$index" :label="__('questions.result.fair_share_of', ['label' => $label])" suffix=" %" />
                            @endif
                        @endforeach
                    </flux:chart.tooltip>
                </flux:chart>
            @endif
        </flux:card>
    @endforeach
</div>
