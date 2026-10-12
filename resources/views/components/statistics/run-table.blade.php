@props([
    'result', {{-- QuestionResult --}}
    'metric', {{-- Metric --}}
])

{{-- The evidence under every number: each run's value, with the way back to its chat. --}}
@php($rows = collect($result->panels)->flatMap(fn ($panel) => collect($panel->groups)->flatMap(fn ($group) => collect($group->runs)->map(fn ($run) => ['panel' => $panel, 'group' => $group, 'run' => $run]))))

@php($runCount = $rows->pluck('run.projectId')->unique()->count())

<flux:accordion {{ $attributes }}>
    <flux:accordion.item :heading="trans_choice('questions.result.run_table', $runCount, ['count' => $runCount])">
    <flux:table>
        <flux:table.columns>
            <flux:table.column>{{ __('questions.result.columns.run') }}</flux:table.column>
            <flux:table.column>{{ __('questions.result.columns.panel') }}</flux:table.column>
            <flux:table.column>{{ __('questions.result.columns.group') }}</flux:table.column>
            <flux:table.column align="end">{{ __('questions.result.columns.value') }}</flux:table.column>
        </flux:table.columns>
        <flux:table.rows>
            @foreach ($rows as $row)
                <flux:table.row wire:key="run-{{ $row['panel']->key }}-{{ $row['group']->key }}-{{ $row['run']->projectId }}">
                    <flux:table.cell><flux:link :href="route('project.show', $row['run']->projectId)">{{ $row['run']->title }}</flux:link></flux:table.cell>
                    <flux:table.cell>{{ $row['panel']->label }}</flux:table.cell>
                    <flux:table.cell>{{ $row['group']->label }}</flux:table.cell>
                    <flux:table.cell align="end" class="tabular-nums">{{ $metric->format($row['run']->value) }}</flux:table.cell>
                </flux:table.row>
            @endforeach
        </flux:table.rows>
    </flux:table>
    </flux:accordion.item>
</flux:accordion>
