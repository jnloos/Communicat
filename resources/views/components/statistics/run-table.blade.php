@use('App\Livewire\Statistics\RunRows')

@props([
    'result', {{-- QuestionResult --}}
    'metric', {{-- Metric --}}
])

{{-- The evidence under every number: one row per run with its value in each group, and the way back to its chat. --}}
@php($rows = RunRows::of($result))
@php($split = collect($result->panels)->contains(fn ($panel) => $panel->label !== ''))

<flux:accordion {{ $attributes }}>
    <flux:accordion.item :heading="trans_choice('questions.result.run_table', count($rows), ['count' => count($rows)])">
    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('questions.result.columns.run') }}</flux:table.column>
            @if ($split)
                <flux:table.column>{{ __('questions.result.columns.panel') }}</flux:table.column>
            @endif
            @foreach ($result->groups as $key => $label)
                <flux:table.column align="end" wire:key="run-column-{{ $key }}">{{ $label !== '' ? $label : __('questions.result.columns.value') }}</flux:table.column>
            @endforeach
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($rows as $row)
                <flux:table.row wire:key="run-{{ $row['projectId'] }}">
                    <flux:table.cell><flux:link :href="route('project.show', $row['projectId'])">{{ $row['title'] }}</flux:link></flux:table.cell>
                    @if ($split)
                        <flux:table.cell>{{ $row['panel'] }}</flux:table.cell>
                    @endif
                    @foreach ($result->groups as $key => $label)
                        <flux:table.cell align="end" class="tabular-nums">{{ isset($row['values'][$key]) ? $metric->format($row['values'][$key]) : '—' }}</flux:table.cell>
                    @endforeach
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
    </flux:accordion.item>
</flux:accordion>
