@props(['questionId'])

{{-- The runs' raw data as CSV; the same entry in the list's and the editor's "…" menu. --}}
<flux:menu.item icon="arrow-down-tray" href="{{ route('statistics.export', $questionId) }}">
    <span class="flex flex-col">
        <span>{{ __('questions.actions.export_raw') }}</span>
        <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">{{ __('questions.actions.export_raw_hint') }}</span>
    </span>
</flux:menu.item>
