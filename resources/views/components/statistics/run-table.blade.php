@props([
    'result', {{-- QuestionResult --}}
    'metric', {{-- Metric --}}
])

{{-- The evidence under every number: each run's value, with the way back to its chat. --}}
@php($rows = collect($result->panels)->flatMap(fn ($panel) => collect($panel->groups)->flatMap(fn ($group) => collect($group->runs)->map(fn ($run) => ['panel' => $panel, 'group' => $group, 'run' => $run]))))

<details {{ $attributes->class('group') }}>
    <summary class="flex cursor-pointer list-none items-center gap-1 text-sm text-zinc-600 dark:text-zinc-300 [&::-webkit-details-marker]:hidden">
        <flux:icon.chevron-right variant="micro" class="transition-transform group-open:rotate-90" />
        {{ trans_choice('questions.result.run_table', $rows->pluck('run.projectId')->unique()->count(), ['count' => $rows->pluck('run.projectId')->unique()->count()]) }}
    </summary>

    <flux:table class="mt-2">
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
</details>
