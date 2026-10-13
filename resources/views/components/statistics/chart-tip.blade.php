@props([
    'heading',
    'rows' => [], {{-- list of ['label' => …, 'value' => …, 'color' => a GroupColor name, or null for no dot] --}}
])

{{--
    The hover card of the hand-drawn charts (point strip, stacked bars), dressed like flux:chart's own
    tooltip: a heading band, then a row per value with its colour dot. flux:tooltip does the hovering
    and placing; its attributes (position, classes, style) go on the wrapper around the slot.
--}}
<flux:tooltip {{ $attributes }}>
    {{ $slot }}

    <flux:tooltip.content class="min-w-40 overflow-hidden rounded-lg! border border-zinc-200 bg-white! p-0! font-normal! text-zinc-500! shadow-lg dark:border-zinc-500! dark:bg-zinc-700! dark:text-zinc-300!">
        <div class="border-b border-zinc-200 bg-zinc-50 p-2 text-xs font-medium text-zinc-800 dark:border-zinc-500 dark:bg-zinc-600 dark:text-zinc-100">{{ $heading }}</div>
        @foreach ($rows as $row)
            <div class="flex items-center gap-2 p-2 text-xs">
                @if ($row['color'] ?? null)
                    <span class="size-2.5 shrink-0 rounded-full" style="background-color: var(--color-{{ $row['color'] }})"></span>
                @endif
                <span class="text-zinc-800 dark:text-white">{{ $row['label'] }}</span>
                <span class="flex-1"></span>
                <span class="tabular-nums">{{ $row['value'] }}</span>
            </div>
        @endforeach
    </flux:tooltip.content>
</flux:tooltip>
